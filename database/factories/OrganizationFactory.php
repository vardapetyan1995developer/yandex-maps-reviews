<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $externalId = (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999);
        $ratings = fake()->numberBetween(50, 5000);

        return [
            'user_id' => User::factory(),
            'source' => 'yandex_maps',
            'external_id' => $externalId,
            'slug' => fake()->slug(2),
            'url' => "https://yandex.ru/maps/org/test/{$externalId}",
            'name' => fake()->company(),
            'address' => fake()->address(),
            'categories' => ['Кафе'],
            'rating' => fake()->randomFloat(1, 1, 5),
            'ratings_count' => $ratings,
            // Reviews always trail ratings: people write text less often than
            // they leave stars
            'reviews_count' => (int) round($ratings * 0.6),
            'reviews_stored' => 0,
            'parse_status' => ParseStatus::Success,
            'last_parsed_at' => now(),
        ];
    }
}
