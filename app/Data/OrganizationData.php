<?php

declare(strict_types=1);

namespace App\Data;

/**
 * An organization card exactly as the source returned it.
 *
 * `ratingsCount` and `reviewsCount` are fundamentally different quantities:
 * far more people leave a star rating than write a review, so collapsing them
 * into a single number is wrong.
 */
final readonly class OrganizationData
{
    /**
     * @param  list<string>  $categories
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $address,
        public ?float $rating,
        public int $ratingsCount,
        public int $reviewsCount,
        public array $categories = [],
        public array $raw = [],
    ) {}

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'rating' => $this->rating,
            'ratings_count' => $this->ratingsCount,
            'reviews_count' => $this->reviewsCount,
            'categories' => $this->categories,
        ];
    }
}
