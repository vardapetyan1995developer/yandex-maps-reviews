<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ReviewRepository;
use App\Data\ReviewData;
use App\Data\ReviewQuery;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\ReviewRevision;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentReviewRepository implements ReviewRepository
{
    /**
     * Chunk size for the IN clause when stamping disappeared reviews.
     *
     * A complete run can carry 600 identifiers. Most databases cope, but an
     * unbounded IN list is the kind of thing that stops working quietly once a
     * source starts serving more, so it is split up front.
     */
    private const ID_CHUNK = 500;

    public function paginateVisible(Organization $organization, ReviewQuery $query): LengthAwarePaginator
    {
        $builder = $this->visible($organization)
            // The revision count flags edited reviews; withCount instead of
            // loading the revisions themselves, of which there may be many
            ->withCount('revisions');

        if ($query->rating !== null) {
            $builder->where('rating', '=', $query->rating);
        }

        foreach ($query->sort->columns() as [$column, $direction]) {
            $builder->orderBy($column, $direction);
        }

        return $builder->paginate(perPage: $query->perPage, page: $query->page);
    }

    public function countVisible(Organization $organization): int
    {
        return $this->visible($organization)->count();
    }

    public function findExistingByExternalIds(Organization $organization, array $externalIds): Collection
    {
        if ($externalIds === []) {
            return new Collection;
        }

        return Review::query()
            ->where('organization_id', '=', $organization->getKey())
            ->whereIn('external_id', $externalIds)
            ->get()
            ->keyBy('external_id');
    }

    public function create(Organization $organization, ReviewData $data, CarbonImmutable $seenAt): Review
    {
        return Review::query()->create([
            'organization_id' => $organization->getKey(),
            'external_id' => $data->externalId,
            'author_name' => $data->authorName,
            'author_avatar' => $data->authorAvatar,
            'rating' => $data->rating,
            'text' => $data->text,
            'published_at' => $data->publishedAt,
            'content_hash' => $data->contentHash(),
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    public function touchLastSeen(Review $review, CarbonImmutable $seenAt): void
    {
        $review->forceFill(['last_seen_at' => $seenAt])->save();
    }

    public function applyChanges(Review $review, ReviewData $data, CarbonImmutable $seenAt): void
    {
        $review->forceFill([
            'author_name' => $data->authorName,
            'author_avatar' => $data->authorAvatar,
            'rating' => $data->rating,
            'text' => $data->text,
            'published_at' => $data->publishedAt,
            'content_hash' => $data->contentHash(),
            'last_seen_at' => $seenAt,
            // The review is back in the listing — clear the disappearance stamp
            'disappeared_at' => null,
        ])->save();
    }

    public function markDisappeared(Organization $organization, array $seenExternalIds, CarbonImmutable $at): int
    {
        $builder = Review::query()
            ->where('organization_id', '=', $organization->getKey())
            ->whereNull('disappeared_at');

        foreach (array_chunk($seenExternalIds, self::ID_CHUNK) as $chunk) {
            $builder->whereNotIn('external_id', $chunk);
        }

        return $builder->update(['disappeared_at' => $at]);
    }

    public function recordRevision(Review $review, ReviewData $data, ?ParseRun $run, CarbonImmutable $at): ReviewRevision
    {
        return ReviewRevision::query()->create([
            'review_id' => $review->getKey(),
            'parse_run_id' => $run?->getKey(),
            // Read before the review is overwritten, so the caller must record
            // the revision first and apply the changes second
            'old_rating' => $review->rating,
            'new_rating' => $data->rating,
            'old_text' => $review->text,
            'new_text' => $data->text,
            'created_at' => $at,
        ]);
    }

    /**
     * Visible means present in the source's latest complete listing.
     *
     * Centralised here so no caller has to remember that a disappeared review
     * still exists as a row but must stay out of every listing and count.
     */
    private function visible(Organization $organization): Builder
    {
        return Review::query()
            ->where('organization_id', '=', $organization->getKey())
            ->whereNull('disappeared_at');
    }
}
