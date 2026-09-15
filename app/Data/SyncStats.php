<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Outcome of writing a parse result to storage.
 *
 * A typed object rather than an associative array so callers cannot misspell a
 * key and silently read null — these numbers end up in the run log and in the
 * interface.
 */
final readonly class SyncStats
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $disappeared,
    ) {}

    public static function empty(): self
    {
        return new self(created: 0, updated: 0, disappeared: 0);
    }

    /** @return array{created: int, updated: int, disappeared: int} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'disappeared' => $this->disappeared,
        ];
    }
}
