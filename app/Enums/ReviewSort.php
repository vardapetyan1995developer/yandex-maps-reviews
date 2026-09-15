<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ordering options for the review listing.
 *
 * An enum rather than a raw string so that an unsupported value cannot reach
 * the query builder: the only way to obtain a case is through `tryFrom`, which
 * the request layer validates against `values()`.
 */
enum ReviewSort: string
{
    case DateDesc = 'date_desc';
    case DateAsc = 'date_asc';
    case RatingDesc = 'rating_desc';
    case RatingAsc = 'rating_asc';

    /**
     * Column/direction pairs applied in order.
     *
     * Every variant ends with a unique column. Without that tie-breaker, rows
     * sharing a timestamp or a rating reorder themselves between queries, and
     * the same review can surface on two different pages.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function columns(): array
    {
        return match ($this) {
            self::DateDesc => [['published_at', 'desc'], ['id', 'desc']],
            self::DateAsc => [['published_at', 'asc'], ['id', 'asc']],
            self::RatingDesc => [['rating', 'desc'], ['published_at', 'desc'], ['id', 'desc']],
            self::RatingAsc => [['rating', 'asc'], ['published_at', 'desc'], ['id', 'desc']],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
