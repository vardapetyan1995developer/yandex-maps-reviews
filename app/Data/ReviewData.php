<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class ReviewData
{
    public function __construct(
        public string $externalId,
        public string $authorName,
        public ?string $authorAvatar,
        public ?int $rating,
        public ?string $text,
        public ?CarbonImmutable $publishedAt,
    ) {}

    /**
     * Hash of the review's meaningful content.
     *
     * Lets a repeat parse tell "we have seen this review already" apart from
     * "the author edited it" — the latter is recorded in the change history.
     */
    public function contentHash(): string
    {
        return hash('xxh128', implode('|', [
            $this->authorName,
            (string) $this->rating,
            (string) $this->text,
            $this->publishedAt?->toIso8601String() ?? '',
        ]));
    }
}
