<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
final class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        $text = fake()->paragraph();
        $rating = fake()->numberBetween(1, 5);
        $author = fake()->name();
        $publishedAt = fake()->dateTimeBetween('-2 years');

        return [
            'organization_id' => Organization::factory(),
            'external_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{24}'),
            'author_name' => $author,
            'author_avatar' => null,
            'rating' => $rating,
            'text' => $text,
            'published_at' => $publishedAt,
            'content_hash' => hash('xxh128', $author.'|'.$rating.'|'.$text),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
