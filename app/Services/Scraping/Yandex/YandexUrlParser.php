<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

use App\Contracts\SourceUrlParser;
use App\Data\SourceReference;
use App\Exceptions\Scraping\InvalidSourceUrlException;

/**
 * Parses a link to an organization card on Yandex.Maps.
 *
 * Supported shapes (all occur in practice — people copy links from the address
 * bar, from the "Share" menu and from the mobile app):
 *
 *   https://yandex.ru/maps/org/yandex/1124715036/
 *   https://yandex.ru/maps/org/yandex/1124715036/reviews/
 *   https://yandex.com/maps/org/1124715036/
 *   https://yandex.ru/maps/213/moscow/?ll=...&oid=1124715036
 *   https://yandex.ru/maps/org/yandex/1124715036/?ll=37.5%2C55.7&z=17
 *
 * The numeric identifier is always the key: a slug changes when the business is
 * renamed, the identifier does not.
 */
final class YandexUrlParser implements SourceUrlParser
{
    public const SOURCE_KEY = 'yandex_maps';

    /**
     * Yandex.Maps domains. The list is closed on purpose: accepting an
     * arbitrary host would let the URL field drive backend requests to anywhere
     * (SSRF).
     */
    private const ALLOWED_HOSTS = [
        'yandex.ru', 'yandex.com', 'yandex.by', 'yandex.kz', 'yandex.uz',
        'yandex.com.tr', 'yandex.com.ge', 'yandex.az', 'yandex.com.am',
    ];

    public function supports(string $url): bool
    {
        try {
            $this->parse($url);

            return true;
        } catch (InvalidSourceUrlException) {
            return false;
        }
    }

    public function parse(string $url): SourceReference
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('~^https?://~i', $url)) {
            throw new InvalidSourceUrlException(
                'Ссылка должна начинаться с http:// или https://',
                ['url' => $url],
            );
        }

        $parts = parse_url($this->percentEncodeNonAscii($url));

        if ($parts === false || ! isset($parts['host'], $parts['path'])) {
            throw new InvalidSourceUrlException('Ссылку не удалось разобрать', ['url' => $url]);
        }

        $host = $this->normaliseHost($parts['host']);

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new InvalidSourceUrlException(
                'Ссылка должна вести на Яндекс.Карты',
                ['url' => $url, 'host' => $host],
            );
        }

        $path = $parts['path'];
        parse_str($parts['query'] ?? '', $query);

        [$externalId, $slug] = $this->extractIdentity($path, $query);

        if ($externalId === null) {
            throw new InvalidSourceUrlException(
                'В ссылке не найден идентификатор организации',
                ['url' => $url, 'path' => $path],
            );
        }

        $canonicalHost = $host === 'yandex.ru' ? 'yandex.ru' : $host;
        $canonical = $slug !== null
            ? sprintf('https://%s/maps/org/%s/%s', $canonicalHost, $slug, $externalId)
            : sprintf('https://%s/maps/org/%s', $canonicalHost, $externalId);

        return new SourceReference(
            source: self::SOURCE_KEY,
            externalId: $externalId,
            slug: $slug,
            canonicalUrl: $canonical,
            originalUrl: $url,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: ?string, 1: ?string}
     */
    private function extractIdentity(string $path, array $query): array
    {
        // /maps/org/<slug>/<id>/... — by far the most common shape
        if (preg_match('~/maps/org/([^/]+)/(\d+)~', $path, $m) === 1) {
            return [$m[2], $this->sanitiseSlug($m[1])];
        }

        // /maps/org/<id>/... — the slugless shape
        if (preg_match('~/maps/org/(\d+)~', $path, $m) === 1) {
            return [$m[1], null];
        }

        // ?oid=<id> — a link produced by search on the map
        $oid = $query['oid'] ?? null;

        if (is_string($oid) && preg_match('~^\d+$~', $oid) === 1) {
            return [$oid, null];
        }

        return [null, null];
    }

    /**
     * Normalise non-ASCII characters to percent-encoding.
     *
     * parse_url mangles raw bytes in the path: a link with a Cyrillic slug
     * pasted from a messenger or the address bar decodes into garbage and a
     * perfectly valid card gets rejected. Browsers copy such links already
     * encoded, but that cannot be relied on.
     */
    private function percentEncodeNonAscii(string $url): string
    {
        return preg_replace_callback(
            '~[^\x20-\x7E]+~',
            static fn (array $matches): string => rawurlencode($matches[0]),
            $url,
        ) ?? $url;
    }

    private function sanitiseSlug(string $slug): ?string
    {
        $slug = rawurldecode($slug);

        return preg_match('~^[\p{L}\p{N}_-]+$~u', $slug) === 1 ? $slug : null;
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower($host);
        $host = preg_replace('~^www\.~', '', $host) ?? $host;

        // m.yandex.ru — the mobile site, same card
        return preg_replace('~^m\.~', '', $host) ?? $host;
    }
}
