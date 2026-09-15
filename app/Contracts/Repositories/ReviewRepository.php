<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Data\ReviewData;
use App\Data\ReviewQuery;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\ReviewRevision;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence for reviews and their edit history.
 *
 * Two pieces of domain knowledge live here rather than in calling code: that a
 * "visible" review is one without a disappearance stamp, and that ordering must
 * always carry a unique tie-breaker so pages cannot overlap.
 */
interface ReviewRepository
{
    /** A page of visible reviews for a card, filtered and ordered. */
    public function paginateVisible(Organization $organization, ReviewQuery $query): LengthAwarePaginator;

    /** How many reviews we currently hold for a card, excluding disappeared ones. */
    public function countVisible(Organization $organization): int;

    /**
     * Load the reviews we already hold among the given source identifiers,
     * keyed by external id.
     *
     * One query for the whole batch: per-row lookups would turn a single save
     * into hundreds of round trips.
     *
     * @param  list<string>  $externalIds
     * @return Collection<string, Review>
     */
    public function findExistingByExternalIds(Organization $organization, array $externalIds): Collection;

    public function create(Organization $organization, ReviewData $data, CarbonImmutable $seenAt): Review;

    /** Record that an unchanged review was seen again, without touching its content. */
    public function touchLastSeen(Review $review, CarbonImmutable $seenAt): void;

    /** Overwrite a review's content and clear any disappearance stamp. */
    public function applyChanges(Review $review, ReviewData $data, CarbonImmutable $seenAt): void;

    /**
     * Stamp the reviews missing from a complete run as disappeared.
     *
     * @param  list<string>  $seenExternalIds
     * @return int number of rows stamped
     */
    public function markDisappeared(Organization $organization, array $seenExternalIds, CarbonImmutable $at): int;

    /** Capture a before/after pair for a review whose content changed. */
    public function recordRevision(Review $review, ReviewData $data, ?ParseRun $run, CarbonImmutable $at): ReviewRevision;
}
