<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A parsed organization link: whatever uniquely identifies a card on a source.
 *
 * The idempotency key is `externalId`, not the URL. A Yandex slug changes when
 * the business is renamed; the numeric identifier does not.
 */
final readonly class SourceReference
{
    public function __construct(
        public string $source,
        public string $externalId,
        public ?string $slug,
        public string $canonicalUrl,
        public string $originalUrl,
    ) {}

    public function reviewsUrl(): string
    {
        return rtrim($this->canonicalUrl, '/').'/reviews/';
    }
}
