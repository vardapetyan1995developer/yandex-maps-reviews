<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

use App\Data\OrganizationData;
use App\Data\SourceReference;
use App\Exceptions\Scraping\EmptyResponseException;
use App\Exceptions\Scraping\OrganizationNotFoundException;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Exceptions\Scraping\SourceSchemaChangedException;
use App\Exceptions\Scraping\SourceUnavailableException;
use App\Services\Scraping\Support\UserAgentRotator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Step one of a parse: fetch the card's HTML and extract the initial
 * application state of Yandex.Maps from it.
 *
 * The card is server-rendered and embeds a JSON blob holding the whole page
 * state (`<script class="state-view">`). Two things come from there:
 *
 *   1. the organization data — name, address, rating and both counters;
 *   2. csrfToken and sessionId, without which the internal API answers 400.
 *
 * The reviews themselves are absent from that JSON: a script loads them after
 * the page renders. That is exactly why parsing the HTML alone is insufficient
 * and a second step is required.
 */
final class YandexBootstrapper
{
    /**
     * The initial-state marker. If it disappears, Yandex has changed how state
     * is delivered — grounds to stop with a clear error rather than silently
     * return nothing.
     */
    private const STATE_PATTERN = '~<script[^>]*class="state-view"[^>]*>(.*?)</script>~s';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly UserAgentRotator $agents,
    ) {}

    public function bootstrap(SourceReference $reference, ?string $proxy = null): BootstrapResult
    {
        $response = $this->fetchPage($reference, $proxy);
        $html = $response->body();

        $this->guardAgainstCaptcha($response, $html, $reference);

        $state = $this->extractState($html, $reference);
        $config = $state['config'] ?? null;

        if (! is_array($config)) {
            throw new SourceSchemaChangedException(
                'В состоянии страницы отсутствует секция config',
                $this->diagnostics($reference, $html, ['state_keys' => array_keys($state)]),
            );
        }

        return new BootstrapResult(
            session: $this->buildSession($config, $response, $reference, $html),
            organization: $this->extractOrganization($state, $reference, $html),
        );
    }

    private function fetchPage(SourceReference $reference, ?string $proxy): Response
    {
        $request = $this->http
            ->withHeaders($this->agents->headers())
            ->withHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
            ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
            ->timeout(config('scraping.timeout', 30))
            ->connectTimeout(config('scraping.connect_timeout', 10));

        if ($proxy !== null) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        try {
            $response = $request->get($reference->reviewsUrl());
        } catch (ConnectionException $e) {
            throw new SourceUnavailableException(
                'Не удалось соединиться с Яндекс.Картами',
                ['url' => $reference->reviewsUrl()],
                $e,
            );
        }

        if ($response->status() === 404) {
            throw new OrganizationNotFoundException(
                'Карточка организации не найдена',
                ['url' => $reference->reviewsUrl(), 'external_id' => $reference->externalId],
            );
        }

        if ($response->status() === 429 || $response->status() === 403) {
            throw new SourceBlockedException(
                'Яндекс ограничил доступ при загрузке карточки',
                ['status' => $response->status(), 'url' => $reference->reviewsUrl()],
            );
        }

        if ($response->failed()) {
            throw new SourceUnavailableException(
                'Яндекс вернул неуспешный ответ при загрузке карточки',
                ['status' => $response->status(), 'url' => $reference->reviewsUrl()],
            );
        }

        if (trim($response->body()) === '') {
            throw new EmptyResponseException(
                'Яндекс вернул пустую страницу',
                ['url' => $reference->reviewsUrl()],
            );
        }

        return $response;
    }

    /**
     * Anti-bot detection.
     *
     * The captcha page is served with status 200, so the status code cannot
     * identify it — we inspect the final URL and the body instead.
     */
    private function guardAgainstCaptcha(Response $response, string $html, SourceReference $reference): void
    {
        $finalUrl = (string) $response->effectiveUri();

        $isCaptcha = str_contains($finalUrl, '/showcaptcha')
            || str_contains($finalUrl, 'captcha.yandex')
            || (str_contains($html, 'SmartCaptcha') && ! str_contains($html, 'state-view'));

        if ($isCaptcha) {
            throw new SourceBlockedException(
                'Яндекс показал капчу вместо карточки организации',
                ['url' => $reference->reviewsUrl(), 'final_url' => $finalUrl],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractState(string $html, SourceReference $reference): array
    {
        if (preg_match(self::STATE_PATTERN, $html, $matches) !== 1) {
            throw new SourceSchemaChangedException(
                'На странице не найден блок начального состояния (state-view)',
                $this->diagnostics($reference, $html),
            );
        }

        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true);

        if (! is_array($decoded)) {
            // Retry without entity decoding: Yandex already serves valid JSON,
            // and decoding entities can corrupt it
            $decoded = json_decode($matches[1], true);
        }

        if (! is_array($decoded)) {
            throw new SourceSchemaChangedException(
                'Блок начального состояния не является валидным JSON',
                $this->diagnostics($reference, $html, ['json_error' => json_last_error_msg()]),
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildSession(array $config, Response $response, SourceReference $reference, string $html): YandexSession
    {
        $csrfToken = $config['csrfToken'] ?? null;
        $sessionId = data_get($config, 'counters.analytics.sessionId') ?? ($config['requestId'] ?? null);

        if (! is_string($csrfToken) || $csrfToken === '') {
            throw new SourceSchemaChangedException(
                'В конфигурации страницы отсутствует csrfToken',
                $this->diagnostics($reference, $html, ['config_keys' => array_keys($config)]),
            );
        }

        if (! is_string($sessionId) || $sessionId === '') {
            throw new SourceSchemaChangedException(
                'В конфигурации страницы отсутствует sessionId',
                $this->diagnostics($reference, $html, ['config_keys' => array_keys($config)]),
            );
        }

        $cookies = [];

        foreach ($response->cookies()->toArray() as $cookie) {
            $cookies[$cookie['Name']] = $cookie['Value'];
        }

        $origin = is_string($config['origin'] ?? null) && $config['origin'] !== ''
            ? $config['origin']
            : 'https://yandex.ru';

        return new YandexSession(
            csrfToken: $csrfToken,
            sessionId: $sessionId,
            locale: is_string($config['locale'] ?? null) ? $config['locale'] : 'ru_RU',
            origin: $origin,
            apiBaseUrl: is_string($config['apiBaseUrl'] ?? null) ? $config['apiBaseUrl'] : '/maps',
            cookies: $cookies,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function extractOrganization(array $state, SourceReference $reference, string $html): OrganizationData
    {
        $items = data_get($state, 'stack.0.results.items');

        if (! is_array($items) || $items === []) {
            throw new OrganizationNotFoundException(
                'В состоянии страницы нет карточки организации',
                $this->diagnostics($reference, $html),
            );
        }

        // The page may carry several objects (neighbouring businesses in the
        // same building, for instance) — pick the one whose id matches the link
        $item = null;

        foreach ($items as $candidate) {
            if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $reference->externalId) {
                $item = $candidate;

                break;
            }
        }

        $item ??= is_array($items[0]) ? $items[0] : null;

        if ($item === null) {
            throw new SourceSchemaChangedException(
                'Не удалось прочитать карточку организации из состояния страницы',
                $this->diagnostics($reference, $html),
            );
        }

        $name = $item['title'] ?? $item['shortTitle'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new SourceSchemaChangedException(
                'У карточки организации отсутствует название',
                $this->diagnostics($reference, $html, ['item_keys' => array_keys($item)]),
            );
        }

        $rating = data_get($item, 'ratingData.ratingValue');

        return new OrganizationData(
            externalId: (string) ($item['id'] ?? $reference->externalId),
            name: $name,
            address: is_string($item['fullAddress'] ?? null) ? $item['fullAddress'] : ($item['address'] ?? null),
            // Yandex returns the rating as a float with a tail (4.900000095367432) —
            // round to one decimal, the way their own interface displays it
            rating: is_numeric($rating) ? round((float) $rating, 1) : null,
            ratingsCount: (int) (data_get($item, 'ratingData.ratingCount') ?? 0),
            reviewsCount: (int) (data_get($item, 'ratingData.reviewCount') ?? 0),
            categories: array_values(array_filter(array_map(
                static fn ($category) => is_array($category) ? ($category['name'] ?? null) : null,
                is_array($item['categories'] ?? null) ? $item['categories'] : [],
            ))),
            raw: ['ratingData' => $item['ratingData'] ?? null, 'seoname' => $item['seoname'] ?? null],
        );
    }

    /**
     * Diagnostic context for "the source changed" errors.
     *
     * A slice of the markup is retained on purpose: without a sample of the
     * response, debugging a broken parser turns into guesswork.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function diagnostics(SourceReference $reference, string $html, array $extra = []): array
    {
        return array_merge([
            'url' => $reference->reviewsUrl(),
            'external_id' => $reference->externalId,
            'html_length' => strlen($html),
            'html_excerpt' => mb_substr(strip_tags($html), 0, 500),
        ], $extra);
    }
}
