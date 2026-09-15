<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\OrganizationRepository;
use App\Data\OrganizationData;
use App\Data\SourceReference;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\ParseRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class EloquentOrganizationRepository implements OrganizationRepository
{
    public function allForUser(User $user): Collection
    {
        return Organization::query()
            ->where('user_id', '=', $user->getKey())
            // Eager-loaded because the listing renders each card's status and
            // progress; without it this is a classic N+1
            ->with('latestParseRun')
            ->latest()
            ->get();
    }

    public function findForUser(int $id, User $user): ?Organization
    {
        return Organization::query()
            ->where('id', '=', $id)
            ->where('user_id', '=', $user->getKey())
            ->with('latestParseRun')
            ->first();
    }

    public function findById(int $id): ?Organization
    {
        return Organization::query()->find($id);
    }

    public function connect(User $user, SourceReference $reference): Organization
    {
        // Keyed on the source plus its own identifier rather than the URL: a
        // slug changes when the business is renamed, the identifier does not
        $organization = Organization::query()->firstOrNew([
            'source' => $reference->source,
            'external_id' => $reference->externalId,
        ]);

        $organization->fill([
            'user_id' => $user->getKey(),
            'slug' => $reference->slug,
            'url' => $reference->canonicalUrl,
            'parse_status' => ParseStatus::Queued,
        ])->save();

        // Refreshed so the caller sees column defaults rather than nulls on a
        // freshly created row
        return $organization->refresh();
    }

    public function applyScrapeResult(
        Organization $organization,
        OrganizationData $data,
        ParseStatus $status,
        int $reviewsStored,
        CarbonImmutable $parsedAt,
    ): void {
        $organization->forceFill([
            'name' => $data->name,
            'address' => $data->address,
            'categories' => $data->categories,
            'rating' => $data->rating,
            'ratings_count' => $data->ratingsCount,
            'reviews_count' => $data->reviewsCount,
            'reviews_stored' => $reviewsStored,
            'parse_status' => $status,
            'last_parsed_at' => $parsedAt,
        ])->save();
    }

    public function updateStatus(Organization $organization, ParseStatus $status): void
    {
        $organization->forceFill(['parse_status' => $status])->save();
    }

    public function recordSnapshot(
        Organization $organization,
        OrganizationData $data,
        ?ParseRun $run,
        CarbonImmutable $at,
    ): OrganizationSnapshot {
        return OrganizationSnapshot::query()->create([
            'organization_id' => $organization->getKey(),
            'parse_run_id' => $run?->getKey(),
            'name' => $data->name,
            'rating' => $data->rating,
            'ratings_count' => $data->ratingsCount,
            'reviews_count' => $data->reviewsCount,
            'reviews_stored' => $organization->reviews_stored,
            'payload' => $data->toSnapshot(),
            'created_at' => $at,
        ]);
    }

    public function delete(Organization $organization): void
    {
        $organization->delete();
    }
}
