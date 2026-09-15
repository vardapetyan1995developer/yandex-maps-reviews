<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ReviewQuery;
use App\Enums\ReviewSort;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * fromArray() is the boundary between validated request input and the
 * repository. Validation already rejects bad values, but this object is the
 * last line before a query is built, and it must not be possible to hand the
 * database a request for a hundred thousand rows.
 */
final class ReviewQueryTest extends TestCase
{
    #[Test]
    public function it_applies_defaults_to_an_empty_request(): void
    {
        $query = ReviewQuery::fromArray([]);

        $this->assertSame(1, $query->page);
        $this->assertSame(ReviewQuery::DEFAULT_PER_PAGE, $query->perPage);
        $this->assertNull($query->rating);
        $this->assertSame(ReviewSort::DateDesc, $query->sort);
    }

    #[Test]
    public function the_brief_requires_fifty_per_page_by_default(): void
    {
        $this->assertSame(50, ReviewQuery::DEFAULT_PER_PAGE);
    }

    #[Test]
    public function it_clamps_an_oversized_page_size(): void
    {
        $query = ReviewQuery::fromArray(['per_page' => 100000]);

        $this->assertSame(ReviewQuery::MAX_PER_PAGE, $query->perPage);
    }

    #[Test]
    public function it_refuses_a_page_size_below_one(): void
    {
        $this->assertSame(1, ReviewQuery::fromArray(['per_page' => 0])->perPage);
        $this->assertSame(1, ReviewQuery::fromArray(['per_page' => -20])->perPage);
    }

    #[Test]
    public function it_refuses_a_page_below_one(): void
    {
        // A zero or negative page would produce a negative OFFSET
        $this->assertSame(1, ReviewQuery::fromArray(['page' => 0])->page);
        $this->assertSame(1, ReviewQuery::fromArray(['page' => -5])->page);
    }

    #[Test]
    public function an_unknown_sort_falls_back_to_the_default(): void
    {
        // Validation rejects this first; the fallback means a bad value can
        // never reach the query builder even if that changes
        $this->assertSame(ReviewSort::DateDesc, ReviewQuery::fromArray(['sort' => 'bogus'])->sort);
    }

    #[Test]
    public function it_reads_numeric_strings_from_the_query_string(): void
    {
        // Query parameters arrive as strings
        $query = ReviewQuery::fromArray(['page' => '3', 'per_page' => '25', 'rating' => '4']);

        $this->assertSame(3, $query->page);
        $this->assertSame(25, $query->perPage);
        $this->assertSame(4, $query->rating);
    }

    #[Test]
    public function every_sort_option_ends_with_a_unique_column(): void
    {
        // Without a unique tie-breaker, rows sharing a value reorder between
        // queries and the same review can appear on two pages
        foreach (ReviewSort::cases() as $sort) {
            $columns = $sort->columns();
            $last = end($columns);

            $this->assertSame('id', $last[0], "Sort {$sort->value} does not end with a unique column");
        }
    }

    #[Test]
    public function the_enum_exposes_its_values_for_validation(): void
    {
        $this->assertEqualsCanonicalizing(
            ['date_desc', 'date_asc', 'rating_desc', 'rating_asc'],
            ReviewSort::values(),
        );
    }
}
