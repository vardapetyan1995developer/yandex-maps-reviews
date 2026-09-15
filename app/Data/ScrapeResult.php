<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The outcome of a single parser run.
 *
 * Alongside the data itself it carries diagnostics: which strategy produced it,
 * how many pages were actually read, and whether we hit the source's output
 * ceiling. Without those it is impossible to tell "this business genuinely has
 * few reviews" from "we were cut off".
 */
final readonly class ScrapeResult
{
    /**
     * @param  list<ReviewData>  $reviews
     */
    public function __construct(
        public OrganizationData $organization,
        public array $reviews,
        public string $strategy,
        public int $pagesFetched,
        public bool $truncated,
        public ?string $truncationReason = null,
    ) {}

    public function reviewCount(): int
    {
        return count($this->reviews);
    }

    /**
     * Share of the source's declared review count that we actually collected.
     *
     * The key health metric for the parser: if a card claims 500 reviews and we
     * returned 20, the parser is broken even though no error was raised.
     */
    public function completeness(): float
    {
        if ($this->organization->reviewsCount <= 0) {
            return 1.0;
        }

        return min(1.0, $this->reviewCount() / $this->organization->reviewsCount);
    }
}
