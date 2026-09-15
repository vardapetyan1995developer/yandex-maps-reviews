<?php

declare(strict_types=1);

namespace App\Services\Scraping\Support;

/**
 * The pause between requests to a source.
 *
 * The delay is randomised deliberately: an even interval between requests is
 * itself a tell-tale sign of automation — a human does not click that way.
 * Jitter costs wall-clock time but markedly reduces the chance of tripping a
 * heuristic.
 */
final class RequestThrottle
{
    public function __construct(
        private readonly int $minDelayMs = 400,
        private readonly int $maxDelayMs = 1200,
    ) {}

    public function wait(): void
    {
        usleep($this->nextDelayMicroseconds());
    }

    /**
     * Exponential backoff for retries after the source refuses us. The cap
     * exists so a job cannot fall asleep for hours inside a single attempt.
     */
    public function backoff(int $attempt, int $capSeconds = 60): void
    {
        $seconds = min($capSeconds, 2 ** max(0, $attempt - 1));
        $jitterMs = random_int(0, 750);

        usleep($seconds * 1_000_000 + $jitterMs * 1000);
    }

    private function nextDelayMicroseconds(): int
    {
        $min = max(0, $this->minDelayMs);
        $max = max($min, $this->maxDelayMs);

        return random_int($min, $max) * 1000;
    }
}
