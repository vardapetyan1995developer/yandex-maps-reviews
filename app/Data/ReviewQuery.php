<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ReviewSort;

/**
 * Filtering and pagination parameters for the review listing.
 *
 * Passing a single object rather than a growing parameter list keeps the
 * repository from accumulating methods such as
 * `findByRatingAndSortAndPage(...)` as new filters appear.
 */
final readonly class ReviewQuery
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?int $rating = null,
        public ReviewSort $sort = ReviewSort::DateDesc,
    ) {}

    /**
     * Build from already-validated request input.
     *
     * The caller is responsible for validation; this method only applies
     * defaults and clamps the page size, so a repository can never be handed a
     * request for a hundred thousand rows.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $perPage = isset($validated['per_page'])
            ? (int) $validated['per_page']
            : self::DEFAULT_PER_PAGE;

        return new self(
            page: isset($validated['page']) ? max(1, (int) $validated['page']) : 1,
            perPage: max(1, min(self::MAX_PER_PAGE, $perPage)),
            rating: isset($validated['rating']) ? (int) $validated['rating'] : null,
            sort: ReviewSort::tryFrom((string) ($validated['sort'] ?? '')) ?? ReviewSort::DateDesc,
        );
    }
}
