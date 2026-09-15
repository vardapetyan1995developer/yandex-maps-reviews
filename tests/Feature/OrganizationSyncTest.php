<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\OrganizationData;
use App\Data\ReviewData;
use App\Data\ScrapeResult;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\Review;
use App\Models\ReviewRevision;
use App\Services\Organizations\OrganizationSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Idempotency and change history — what separates a repeat parse from piling
 * junk up in the database.
 */
final class OrganizationSyncTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationSyncService $sync;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = app(OrganizationSyncService::class);
        $this->organization = Organization::factory()->create([
            'parse_status' => ParseStatus::Pending,
        ]);
    }

    private function organizationData(int $reviewsCount = 2, float $rating = 4.5): OrganizationData
    {
        return new OrganizationData(
            externalId: $this->organization->external_id,
            name: 'Старый Лоцман',
            address: 'Мышкин, улица Ленина, 2',
            rating: $rating,
            ratingsCount: 196,
            reviewsCount: $reviewsCount,
            categories: ['Кафе'],
        );
    }

    private function review(string $id, int $rating = 5, string $text = 'Отлично'): ReviewData
    {
        return new ReviewData(
            externalId: $id,
            authorName: 'Иван',
            authorAvatar: null,
            rating: $rating,
            text: $text,
            publishedAt: CarbonImmutable::parse('2026-09-01T10:00:00Z'),
        );
    }

    private function scrapeResult(array $reviews, bool $truncated = false, ?OrganizationData $org = null): ScrapeResult
    {
        return new ScrapeResult(
            organization: $org ?? $this->organizationData(count($reviews)),
            reviews: $reviews,
            strategy: 'internal_api',
            pagesFetched: 1,
            truncated: $truncated,
            truncationReason: $truncated ? 'source_depth_limit' : null,
        );
    }

    #[Test]
    public function a_repeated_parse_updates_rather_than_duplicates(): void
    {
        $reviews = [$this->review('a1'), $this->review('a2')];

        $first = $this->sync->sync($this->organization, $this->scrapeResult($reviews));
        $second = $this->sync->sync($this->organization->fresh(), $this->scrapeResult($reviews));

        $this->assertSame(2, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(2, Review::count());
    }

    #[Test]
    public function it_records_a_revision_when_a_review_is_edited(): void
    {
        $this->sync->sync($this->organization, $this->scrapeResult([$this->review('a1', 5, 'Отлично')]));

        // The author rewrote the review and lowered the rating — for a
        // reputation service that is an event, not merely a new field value
        $this->sync->sync(
            $this->organization->fresh(),
            $this->scrapeResult([$this->review('a1', 2, 'Передумал, всё плохо')]),
        );

        $this->assertSame(1, Review::count());
        $this->assertSame(1, ReviewRevision::count());

        $revision = ReviewRevision::first();
        $this->assertSame(5, $revision->old_rating);
        $this->assertSame(2, $revision->new_rating);
        $this->assertSame('Отлично', $revision->old_text);

        $this->assertSame(2, Review::first()->rating);
    }

    #[Test]
    public function it_does_not_create_a_revision_when_nothing_changed(): void
    {
        $this->sync->sync($this->organization, $this->scrapeResult([$this->review('a1')]));
        $this->sync->sync($this->organization->fresh(), $this->scrapeResult([$this->review('a1')]));

        $this->assertSame(0, ReviewRevision::count());
    }

    #[Test]
    public function it_marks_reviews_that_vanished_from_a_complete_parse(): void
    {
        $this->sync->sync($this->organization, $this->scrapeResult([
            $this->review('a1'),
            $this->review('a2'),
        ]));

        $stats = $this->sync->sync($this->organization->fresh(), $this->scrapeResult([$this->review('a1')]));

        $this->assertSame(1, $stats->disappeared);
        $this->assertNotNull(Review::where('external_id', 'a2')->first()->disappeared_at);
        // The row is not deleted: a hidden review is a valuable fact, not noise
        $this->assertSame(2, Review::count());
    }

    #[Test]
    public function it_never_marks_reviews_as_vanished_after_a_truncated_parse(): void
    {
        $this->sync->sync($this->organization, $this->scrapeResult([
            $this->review('a1'),
            $this->review('a2'),
        ]));

        // Collection broke off: the "missing" reviews were simply never
        // requested, and flagging them would corrupt the data
        $stats = $this->sync->sync(
            $this->organization->fresh(),
            $this->scrapeResult([$this->review('a1')], truncated: true),
        );

        $this->assertSame(0, $stats->disappeared);
        $this->assertNull(Review::where('external_id', 'a2')->first()->disappeared_at);
    }

    #[Test]
    public function a_truncated_parse_is_stored_as_partial_not_success(): void
    {
        $this->sync->sync($this->organization, $this->scrapeResult([$this->review('a1')], truncated: true));

        $this->assertSame(ParseStatus::Partial, $this->organization->fresh()->parse_status);
    }

    #[Test]
    public function every_parse_writes_a_snapshot_that_can_be_diffed(): void
    {
        $this->sync->sync(
            $this->organization,
            $this->scrapeResult([$this->review('a1')], org: $this->organizationData(10, 4.5)),
        );

        $this->sync->sync(
            $this->organization->fresh(),
            $this->scrapeResult([$this->review('a1')], org: $this->organizationData(14, 4.8)),
        );

        $snapshots = OrganizationSnapshot::orderBy('id')->get();
        $this->assertCount(2, $snapshots);

        $diff = $snapshots[1]->diffFrom($snapshots[0]);

        $this->assertSame(['from' => 4.5, 'to' => 4.8], $diff['rating']);
        $this->assertSame(['from' => 10, 'to' => 14], $diff['reviews_count']);
    }

    #[Test]
    public function it_restores_a_review_that_came_back_to_the_source(): void
    {
        $this->sync->sync($this->organization, $this->scrapeResult([$this->review('a1'), $this->review('a2')]));
        $this->sync->sync($this->organization->fresh(), $this->scrapeResult([$this->review('a1')]));

        $this->assertNotNull(Review::where('external_id', 'a2')->first()->disappeared_at);

        $this->sync->sync($this->organization->fresh(), $this->scrapeResult([$this->review('a1'), $this->review('a2')]));

        $this->assertNull(Review::where('external_id', 'a2')->first()->disappeared_at);
    }
}
