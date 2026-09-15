<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Data\ScrapeResult;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\ReviewRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes a parse result to the database.
 *
 * This is where idempotency lives: re-running a parse for the same organization
 * creates no duplicates but updates the existing records and captures exactly
 * what changed since last time.
 *
 * Three rules hold it together:
 *
 *   1. a review's key is the pair (organization_id, external_id) — never its
 *      text or its author;
 *   2. a change to a review's content is written to history, never silently
 *      overwritten;
 *   3. a review that has disappeared is not deleted but stamped with a
 *      disappearance date — for a reputation service, deleted negative feedback
 *      matters no less than new feedback.
 */
final class OrganizationSyncService
{
    /**
     * @return array{created: int, updated: int, disappeared: int}
     */
    public function sync(Organization $organization, ScrapeResult $result, ?ParseRun $run = null): array
    {
        return DB::transaction(function () use ($organization, $result, $run): array {
            $now = CarbonImmutable::now();

            $stats = $this->syncReviews($organization, $result, $run, $now);

            $organization->fill([
                'name' => $result->organization->name,
                'address' => $result->organization->address,
                'categories' => $result->organization->categories,
                'rating' => $result->organization->rating,
                'ratings_count' => $result->organization->ratingsCount,
                'reviews_count' => $result->organization->reviewsCount,
                'reviews_stored' => $organization->reviews()->whereNull('disappeared_at')->count(),
                'parse_status' => $result->truncated ? ParseStatus::Partial : ParseStatus::Success,
                'last_parsed_at' => $now,
            ])->save();

            $this->recordSnapshot($organization, $result, $run, $now);

            return $stats;
        });
    }

    /**
     * @return array{created: int, updated: int, disappeared: int}
     */
    private function syncReviews(Organization $organization, ScrapeResult $result, ?ParseRun $run, CarbonImmutable $now): array
    {
        if ($result->reviews === []) {
            return ['created' => 0, 'updated' => 0, 'disappeared' => 0];
        }

        // Load existing reviews in a single query and hold them in memory: 600
        // rows is not much, whereas per-row SELECTs would turn this save into
        // 600 round trips to the database.
        $existing = $organization->reviews()
            ->whereIn('external_id', array_map(
                static fn ($review) => $review->externalId,
                $result->reviews,
            ))
            ->get()
            ->keyBy('external_id');

        $created = 0;
        $updated = 0;
        $seenIds = [];

        foreach ($result->reviews as $data) {
            $seenIds[] = $data->externalId;
            $hash = $data->contentHash();

            /** @var Review|null $review */
            $review = $existing->get($data->externalId);

            if ($review === null) {
                Review::create([
                    'organization_id' => $organization->id,
                    'external_id' => $data->externalId,
                    'author_name' => $data->authorName,
                    'author_avatar' => $data->authorAvatar,
                    'rating' => $data->rating,
                    'text' => $data->text,
                    'published_at' => $data->publishedAt,
                    'content_hash' => $hash,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                $created++;

                continue;
            }

            // Content unchanged — refresh only the "seen just now" stamp. This
            // is the most frequent case and must also be the cheapest.
            if ($review->content_hash === $hash && $review->disappeared_at === null) {
                $review->forceFill(['last_seen_at' => $now])->save();

                continue;
            }

            if ($review->content_hash !== $hash) {
                $this->recordRevision($review, $data->rating, $data->text, $run);
                $updated++;
            }

            $review->forceFill([
                'author_name' => $data->authorName,
                'author_avatar' => $data->authorAvatar,
                'rating' => $data->rating,
                'text' => $data->text,
                'published_at' => $data->publishedAt,
                'content_hash' => $hash,
                'last_seen_at' => $now,
                // The review is back in the listing — clear the disappearance stamp
                'disappeared_at' => null,
            ])->save();
        }

        $disappeared = $this->markDisappeared($organization, $result, $seenIds, $now);

        return ['created' => $created, 'updated' => $updated, 'disappeared' => $disappeared];
    }

    /**
     * Flag reviews that are no longer in the listing.
     *
     * An important caveat: this may only be done after a complete run. If
     * collection broke off or hit the depth limit, the "missing" reviews were
     * simply never requested, and flagging them would corrupt the data.
     *
     * @param  list<string>  $seenIds
     */
    private function markDisappeared(Organization $organization, ScrapeResult $result, array $seenIds, CarbonImmutable $now): int
    {
        if ($result->truncated) {
            return 0;
        }

        return $organization->reviews()
            ->whereNotIn('external_id', $seenIds)
            ->whereNull('disappeared_at')
            ->update(['disappeared_at' => $now]);
    }

    private function recordRevision(Review $review, ?int $newRating, ?string $newText, ?ParseRun $run): void
    {
        ReviewRevision::create([
            'review_id' => $review->id,
            'parse_run_id' => $run?->id,
            'old_rating' => $review->rating,
            'new_rating' => $newRating,
            'old_text' => $review->text,
            'new_text' => $newText,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function recordSnapshot(Organization $organization, ScrapeResult $result, ?ParseRun $run, CarbonImmutable $now): void
    {
        OrganizationSnapshot::create([
            'organization_id' => $organization->id,
            'parse_run_id' => $run?->id,
            'name' => $result->organization->name,
            'rating' => $result->organization->rating,
            'ratings_count' => $result->organization->ratingsCount,
            'reviews_count' => $result->organization->reviewsCount,
            'reviews_stored' => $organization->reviews_stored,
            'payload' => $result->organization->toSnapshot(),
            'created_at' => $now,
        ]);
    }
}
