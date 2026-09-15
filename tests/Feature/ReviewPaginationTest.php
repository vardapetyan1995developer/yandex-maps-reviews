<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::factory()->for($this->user)->create();
    }

    private function seedReviews(int $count): void
    {
        Review::factory()
            ->count($count)
            ->for($this->organization)
            ->create();
    }

    #[Test]
    public function it_returns_fifty_reviews_per_page_by_default(): void
    {
        $this->seedReviews(137);

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews")
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 137)
            ->assertJsonPath('meta.last_page', 3);
    }

    #[Test]
    public function the_last_page_returns_the_remainder(): void
    {
        $this->seedReviews(137);

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews?page=3")
            ->assertOk()
            ->assertJsonCount(37, 'data')
            ->assertJsonPath('meta.from', 101)
            ->assertJsonPath('meta.to', 137);
    }

    #[Test]
    public function pages_do_not_overlap_even_when_dates_are_identical(): void
    {
        // An identical timestamp on every review is precisely the case where
        // ordering without a secondary key drifts and a row lands on two pages
        Review::factory()
            ->count(120)
            ->for($this->organization)
            ->create(['published_at' => '2026-01-01 12:00:00']);

        $ids = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->actingAs($this->user)
                ->getJson("/api/organizations/{$this->organization->id}/reviews?page={$page}");

            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
        }

        $this->assertCount(120, $ids);
        $this->assertSame(120, count(array_unique($ids)), 'Reviews were duplicated across pages');
    }

    #[Test]
    public function it_exposes_author_date_text_and_rating_for_each_review(): void
    {
        $this->seedReviews(1);

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'author' => ['name', 'avatar'], 'rating', 'text', 'published_at']],
            ]);
    }

    #[Test]
    public function it_can_filter_by_rating(): void
    {
        Review::factory()->count(5)->for($this->organization)->create(['rating' => 1]);
        Review::factory()->count(9)->for($this->organization)->create(['rating' => 5]);

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews?rating=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    #[Test]
    public function it_can_sort_by_rating(): void
    {
        Review::factory()->for($this->organization)->create(['rating' => 5]);
        Review::factory()->for($this->organization)->create(['rating' => 1]);
        Review::factory()->for($this->organization)->create(['rating' => 3]);

        $ratings = $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews?sort=rating_asc")
            ->assertOk()
            ->json('data.*.rating');

        $this->assertSame([1, 3, 5], $ratings);
    }

    #[Test]
    public function it_hides_reviews_that_disappeared_from_the_source(): void
    {
        Review::factory()->count(3)->for($this->organization)->create();
        Review::factory()->for($this->organization)->create(['disappeared_at' => now()]);

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews")
            ->assertOk()
            // The row stays in the database as history but is kept out of the listing
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function it_rejects_an_oversized_page_size(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$this->organization->id}/reviews?per_page=5000")
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    #[Test]
    public function a_user_cannot_read_another_users_reviews(): void
    {
        $foreign = Organization::factory()->create();
        Review::factory()->for($foreign)->create();

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$foreign->id}/reviews")
            ->assertNotFound();
    }
}
