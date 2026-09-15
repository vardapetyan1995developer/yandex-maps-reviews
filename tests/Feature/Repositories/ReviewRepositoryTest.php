<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Contracts\Repositories\ReviewRepository;
use App\Data\ReviewQuery;
use App\Enums\ReviewSort;
use App\Models\Organization;
use App\Models\Review;
use App\Repositories\Eloquent\EloquentReviewRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The repository owns two pieces of domain knowledge that callers must not have
 * to remember: what "visible" means, and that ordering always needs a unique
 * tie-breaker. Both are pinned here.
 */
final class ReviewRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ReviewRepository $repository;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ReviewRepository::class);
        $this->organization = Organization::factory()->create();
    }

    #[Test]
    public function the_contract_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(EloquentReviewRepository::class, app(ReviewRepository::class));
    }

    #[Test]
    public function visible_listings_exclude_disappeared_reviews(): void
    {
        Review::factory()->count(3)->for($this->organization)->create();
        Review::factory()->for($this->organization)->create(['disappeared_at' => now()]);

        $this->assertSame(3, $this->repository->countVisible($this->organization));
        $this->assertSame(3, $this->repository->paginateVisible($this->organization, new ReviewQuery)->total());
    }

    #[Test]
    public function ordering_stays_stable_when_every_timestamp_is_identical(): void
    {
        Review::factory()
            ->count(30)
            ->for($this->organization)
            ->create(['published_at' => '2026-01-01 12:00:00']);

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $result = $this->repository->paginateVisible(
                $this->organization,
                new ReviewQuery(page: $page, perPage: 10),
            );

            $seen = array_merge($seen, $result->pluck('id')->all());
        }

        // Without the id tie-breaker the same row surfaces on two pages
        $this->assertCount(30, array_unique($seen));
    }

    #[Test]
    public function it_filters_by_rating_and_orders_by_it(): void
    {
        Review::factory()->count(4)->for($this->organization)->create(['rating' => 1]);
        Review::factory()->count(6)->for($this->organization)->create(['rating' => 5]);

        $onlyOnes = $this->repository->paginateVisible(
            $this->organization,
            new ReviewQuery(rating: 1),
        );

        $this->assertSame(4, $onlyOnes->total());

        $ascending = $this->repository->paginateVisible(
            $this->organization,
            new ReviewQuery(perPage: 10, sort: ReviewSort::RatingAsc),
        );

        $this->assertSame(1, $ascending->first()->rating);
    }

    #[Test]
    public function existing_reviews_are_returned_keyed_by_external_id(): void
    {
        $review = Review::factory()->for($this->organization)->create(['external_id' => 'abc']);
        Review::factory()->for($this->organization)->create(['external_id' => 'def']);

        $found = $this->repository->findExistingByExternalIds($this->organization, ['abc', 'missing']);

        $this->assertCount(1, $found);
        $this->assertSame($review->id, $found->get('abc')->id);
    }

    #[Test]
    public function an_empty_identifier_list_does_not_hit_the_database(): void
    {
        Review::factory()->count(2)->for($this->organization)->create();

        // An unguarded whereIn([]) would match nothing and still cost a query;
        // worse, a caller could mistake the result for "nothing exists yet"
        $this->assertTrue($this->repository->findExistingByExternalIds($this->organization, [])->isEmpty());
    }

    #[Test]
    public function marking_disappeared_spares_the_reviews_that_were_seen(): void
    {
        $kept = Review::factory()->for($this->organization)->create(['external_id' => 'kept']);
        $gone = Review::factory()->for($this->organization)->create(['external_id' => 'gone']);

        $stamped = $this->repository->markDisappeared(
            $this->organization,
            ['kept'],
            CarbonImmutable::now(),
        );

        $this->assertSame(1, $stamped);
        $this->assertNull($kept->fresh()->disappeared_at);
        $this->assertNotNull($gone->fresh()->disappeared_at);
    }

    #[Test]
    public function reviews_of_another_organization_are_never_touched(): void
    {
        $foreign = Organization::factory()->create();
        $foreignReview = Review::factory()->for($foreign)->create(['external_id' => 'gone']);

        Review::factory()->for($this->organization)->create(['external_id' => 'gone']);

        $this->repository->markDisappeared($this->organization, [], CarbonImmutable::now());

        $this->assertNull($foreignReview->fresh()->disappeared_at);
    }
}
