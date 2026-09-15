<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Contracts\Repositories\OrganizationRepository;
use App\Contracts\Repositories\ParseRunRepository;
use App\Contracts\Repositories\ReviewRepository;
use App\Data\ReviewData;
use App\Data\ScrapeResult;
use App\Data\SyncStats;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes a parse result to storage.
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
 *
 * The service owns the transaction and the decision-making; the repositories
 * own the queries. The boundary is deliberate — splitting a single transaction
 * across two classes would be a real defect, not a matter of taste.
 */
final readonly class OrganizationSyncService
{
    public function __construct(
        private OrganizationRepository $organizations,
        private ReviewRepository $reviews,
        private ParseRunRepository $parseRuns,
    ) {}

    public function sync(Organization $organization, ScrapeResult $result, ?ParseRun $run = null): SyncStats
    {
        return DB::transaction(function () use ($organization, $result, $run): SyncStats {
            $now = CarbonImmutable::now();

            $stats = $this->syncReviews($organization, $result, $run, $now);

            $this->organizations->applyScrapeResult(
                organization: $organization,
                data: $result->organization,
                status: $this->parseRuns->statusFor($result),
                reviewsStored: $this->reviews->countVisible($organization),
                parsedAt: $now,
            );

            $this->organizations->recordSnapshot($organization, $result->organization, $run, $now);

            return $stats;
        });
    }

    private function syncReviews(
        Organization $organization,
        ScrapeResult $result,
        ?ParseRun $run,
        CarbonImmutable $now,
    ): SyncStats {
        if ($result->reviews === []) {
            return SyncStats::empty();
        }

        $existing = $this->reviews->findExistingByExternalIds(
            $organization,
            array_map(static fn (ReviewData $review): string => $review->externalId, $result->reviews),
        );

        $created = 0;
        $updated = 0;
        $seenIds = [];

        foreach ($result->reviews as $data) {
            $seenIds[] = $data->externalId;

            if ($this->persist($organization, $data, $existing, $run, $now)) {
                $existing->has($data->externalId) ? $updated++ : $created++;
            }
        }

        return new SyncStats(
            created: $created,
            updated: $updated,
            disappeared: $this->markDisappeared($organization, $result, $seenIds, $now),
        );
    }

    /**
     * Persist one review.
     *
     * @param  Collection<string, Review>  $existing
     * @return bool whether the row was created or its content changed
     */
    private function persist(
        Organization $organization,
        ReviewData $data,
        Collection $existing,
        ?ParseRun $run,
        CarbonImmutable $now,
    ): bool {
        $review = $existing->get($data->externalId);

        if (! $review instanceof Review) {
            $this->reviews->create($organization, $data, $now);

            return true;
        }

        $hasChanged = $review->content_hash !== $data->contentHash();

        // Content unchanged — refresh only the "seen just now" stamp. This is
        // the most frequent case by far and must also be the cheapest.
        if (! $hasChanged && $review->disappeared_at === null) {
            $this->reviews->touchLastSeen($review, $now);

            return false;
        }

        // The revision is recorded before the change is applied, because it
        // reads the review's current values as the "before" side
        if ($hasChanged) {
            $this->reviews->recordRevision($review, $data, $run, $now);
        }

        $this->reviews->applyChanges($review, $data, $now);

        return $hasChanged;
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
    private function markDisappeared(
        Organization $organization,
        ScrapeResult $result,
        array $seenIds,
        CarbonImmutable $now,
    ): int {
        if ($result->truncated) {
            return 0;
        }

        return $this->reviews->markDisappeared($organization, $seenIds, $now);
    }
}
