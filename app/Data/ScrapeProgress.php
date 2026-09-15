<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A progress snapshot of a long-running parse — what the user sees while the
 * queued job is still working.
 */
final readonly class ScrapeProgress
{
    public function __construct(
        public int $pagesFetched,
        public int $reviewsFetched,
        public ?int $totalExpected,
    ) {}

    public function percent(): int
    {
        if (! $this->totalExpected || $this->totalExpected <= 0) {
            return 0;
        }

        return (int) min(100, round($this->reviewsFetched / $this->totalExpected * 100));
    }
}
