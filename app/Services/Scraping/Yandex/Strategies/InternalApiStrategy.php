<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex\Strategies;

use App\Contracts\ScrapeStrategy;
use App\Data\ReviewData;
use App\Data\ScrapeProgress;
use App\Data\ScrapeResult;
use App\Data\SourceReference;
use App\Exceptions\Scraping\ScrapingException;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Exceptions\Scraping\SourceUnavailableException;
use App\Services\Scraping\Support\ProxyPool;
use App\Services\Scraping\Support\RequestThrottle;
use App\Services\Scraping\Support\UserAgentRotator;
use App\Services\Scraping\Yandex\RequestSigner;
use App\Services\Scraping\Yandex\ReviewsResponseValidator;
use App\Services\Scraping\Yandex\YandexBootstrapper;
use App\Services\Scraping\Yandex\YandexSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The primary strategy: talking to the card's internal JSON API.
 *
 * How it works:
 *
 *   1. load the card's HTML once and extract csrfToken, sessionId and the
 *      organization data from it (see YandexBootstrapper);
 *   2. request /maps/api/business/fetchReviews page by page, signing every
 *      request (see RequestSigner);
 *   3. validate the shape of each response and stop when reviews run out or we
 *      hit the source's output ceiling.
 *
 * Why this rather than a headless browser: collecting all ~600 reviews takes 13
 * HTTP requests and a few seconds, against minutes of scrolling a page, at
 * incomparably lower memory cost. The price is fragility — the contract is
 * internal and undocumented. Hence the source keeps a fallback path.
 */
final class InternalApiStrategy implements ScrapeStrategy
{
    public const KEY = 'internal_api';

    /**
     * Page size. The source caps it hard: with pageSize > 50 the response comes
     * back carrying a nested error, so it cannot be raised.
     */
    private const PAGE_SIZE = 50;

    /**
     * A guard against an infinite loop should the source start returning broken
     * pagination. The real output depth is considerably lower.
     */
    private const MAX_PAGES = 40;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly YandexBootstrapper $bootstrapper,
        private readonly RequestSigner $signer,
        private readonly ReviewsResponseValidator $validator,
        private readonly RequestThrottle $throttle,
        private readonly UserAgentRotator $agents,
        private readonly ProxyPool $proxies,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function scrape(SourceReference $reference, ?callable $onProgress = null): ScrapeResult
    {
        $proxy = $this->proxies->acquire();
        $this->agents->pin();

        try {
            $result = $this->run($reference, $proxy, $onProgress);
            $this->proxies->markHealthy($proxy);

            return $result;
        } catch (SourceBlockedException $e) {
            // Take the blocked address out of rotation, otherwise the next job
            // goes through it again and only extends the block
            $this->proxies->markBlocked($proxy);

            throw $e;
        }
    }

    private function run(SourceReference $reference, ?string $proxy, ?callable $onProgress): ScrapeResult
    {
        $bootstrap = $this->bootstrapper->bootstrap($reference, $proxy);
        $session = $bootstrap->session;
        $organization = $bootstrap->organization;

        $reviews = [];
        $seenIds = [];
        $page = 1;
        $pagesFetched = 0;
        $truncated = false;
        $truncationReason = null;
        $maxReviews = (int) config('scraping.yandex.max_reviews', 600);

        while ($page <= self::MAX_PAGES) {
            // The source does not serve reviews beyond a fixed offset: a request
            // past that boundary reliably returns an error. Stop early so we
            // neither waste a doomed request nor record a routine output limit
            // as a source failure.
            if (count($reviews) >= $maxReviews) {
                $truncated = true;
                $truncationReason = 'source_depth_limit';

                break;
            }

            try {
                $payload = $this->fetchPage($reference, $session, $page, $proxy);
            } catch (ScrapingException $e) {
                // Breaking off midway is a partial result, not a total failure:
                // the reviews already gathered are valuable and must be saved.
                // Only failing to read even the first page is a hard failure.
                if ($reviews === []) {
                    throw $e;
                }

                $truncated = true;
                $truncationReason = $e->reason()->value;

                Log::warning('Review collection interrupted midway', [
                    'source' => $reference->source,
                    'external_id' => $reference->externalId,
                    'page' => $page,
                    'collected' => count($reviews),
                    'reason' => $e->reason()->value,
                    'message' => $e->getMessage(),
                ]);

                break;
            }

            $pagesFetched++;
            $batch = $payload['reviews'];
            $params = $payload['params'];

            if ($batch === []) {
                break;
            }

            foreach ($batch as $raw) {
                $review = $this->mapReview($raw);

                if ($review === null) {
                    continue;
                }

                // The source occasionally repeats the same review across
                // adjacent pages (pinned reviews); drop duplicates on the fly
                if (isset($seenIds[$review->externalId])) {
                    continue;
                }

                $seenIds[$review->externalId] = true;
                $reviews[] = $review;
            }

            if ($onProgress !== null) {
                $onProgress(new ScrapeProgress(
                    pagesFetched: $pagesFetched,
                    reviewsFetched: count($reviews),
                    totalExpected: $this->expectedTotal($params, $organization->reviewsCount),
                ));
            }

            $totalPages = (int) ($params['totalPages'] ?? 0);
            $remained = $params['reviewsRemained'] ?? null;

            if ($remained !== null && (int) $remained <= 0) {
                break;
            }

            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $page++;
            $this->throttle->wait();
        }

        // The source claims more reviews than it served: expected, given the
        // output depth limit, but the incompleteness must be recorded
        // explicitly so the interface does not present this as the full picture
        if (! $truncated && $organization->reviewsCount > count($reviews)) {
            $truncated = true;
            $truncationReason = 'source_depth_limit';
        }

        return new ScrapeResult(
            organization: $organization,
            reviews: $reviews,
            strategy: self::KEY,
            pagesFetched: $pagesFetched,
            truncated: $truncated,
            truncationReason: $truncationReason,
        );
    }

    /**
     * @return array{reviews: list<array<string, mixed>>, params: array<string, mixed>}
     */
    private function fetchPage(SourceReference $reference, YandexSession $session, int $page, ?string $proxy): array
    {
        $query = $this->signer->sign([
            'ajax' => '1',
            'csrfToken' => $session->csrfToken,
            'sessionId' => $session->sessionId,
            'businessId' => $reference->externalId,
            'ranking' => config('scraping.yandex.ranking', 'by_time'),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'locale' => $session->locale,
        ]);

        $url = $session->endpoint('business/fetchReviews').'?'.$query;

        $request = $this->http
            ->withHeaders($this->agents->headers())
            ->withHeader('Accept', 'application/json, text/plain, */*')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Referer', $reference->reviewsUrl())
            ->withHeader('X-Retpath-Y', $reference->reviewsUrl())
            ->withCookies($session->cookies, parse_url($session->origin, PHP_URL_HOST) ?: 'yandex.ru')
            ->timeout(config('scraping.timeout', 30))
            ->connectTimeout(config('scraping.connect_timeout', 10));

        if ($proxy !== null) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException $e) {
            throw new SourceUnavailableException(
                'Обрыв соединения при запросе страницы отзывов',
                ['page' => $page, 'external_id' => $reference->externalId],
                $e,
            );
        }

        $context = [
            'page' => $page,
            'external_id' => $reference->externalId,
            'http_status' => $response->status(),
            'body_excerpt' => mb_substr($response->body(), 0, 500),
        ];

        if ($response->status() === 429 || $response->status() === 403) {
            throw new SourceBlockedException('Источник ограничил частоту запросов', $context);
        }

        if ($response->serverError()) {
            throw new SourceUnavailableException('Источник вернул серверную ошибку', $context);
        }

        return $this->validator->validate($response->json(), $context);
    }

    /**
     * How many reviews we realistically expect to collect.
     *
     * Progress uses the lesser of the business's declared review count and the
     * output depth limit, rather than the declared count alone: otherwise the
     * progress bar for a card with 5000 reviews would freeze at 12% and look
     * like a hang.
     *
     * @param  array<string, mixed>  $params
     */
    private function expectedTotal(array $params, int $declaredReviews): ?int
    {
        $count = (int) ($params['count'] ?? $declaredReviews);
        $cap = (int) config('scraping.yandex.max_reviews', 600);

        if ($count <= 0) {
            return null;
        }

        return min($count, $cap);
    }

    /**
     * @param  mixed  $raw
     */
    private function mapReview($raw): ?ReviewData
    {
        if (! is_array($raw)) {
            return null;
        }

        $externalId = $raw['reviewId'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        $author = is_array($raw['author'] ?? null) ? $raw['author'] : [];
        $name = $author['name'] ?? null;
        $rating = $raw['rating'] ?? null;
        $text = $raw['text'] ?? null;

        return new ReviewData(
            externalId: $externalId,
            // An anonymous author is a normal case: the person hid their name,
            // it is not a parser failure
            authorName: is_string($name) && $name !== '' ? $name : 'Аноним',
            authorAvatar: $this->avatarUrl($author['avatarUrl'] ?? null),
            rating: is_numeric($rating) ? (int) $rating : null,
            text: is_string($text) ? $text : null,
            publishedAt: $this->parseDate($raw['updatedTime'] ?? null),
        );
    }

    private function avatarUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        // Yandex returns a template of the form .../{size} — substitute a concrete size
        return str_replace('{size}', 'islands-68', $url);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
