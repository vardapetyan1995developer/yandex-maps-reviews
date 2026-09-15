<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Data\OrganizationData;
use App\Data\SourceReference;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\ParseRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persistence for organization cards and their aggregate snapshots.
 *
 * The methods are named after domain intentions rather than CRUD verbs. That is
 * the difference between a repository that carries knowledge — which card
 * belongs to which user, what "connecting" a card means, what gets frozen in a
 * snapshot — and a pass-through wrapper that only forwards calls to the ORM.
 */
interface OrganizationRepository
{
    /** Every card connected by a user, with its latest run eager-loaded. */
    public function allForUser(User $user): Collection;

    /** A card by id, but only if it belongs to this user. */
    public function findForUser(int $id, User $user): ?Organization;

    public function findById(int $id): ?Organization;

    /**
     * Connect a card, or re-attach one that already exists.
     *
     * Keyed on (source, external_id): submitting the same link twice updates
     * the existing record instead of creating a second one. This is the
     * entry point for the idempotency guarantee.
     */
    public function connect(User $user, SourceReference $reference): Organization;

    /** Persist the figures returned by a completed parse. */
    public function applyScrapeResult(
        Organization $organization,
        OrganizationData $data,
        ParseStatus $status,
        int $reviewsStored,
        CarbonImmutable $parsedAt,
    ): void;

    public function updateStatus(Organization $organization, ParseStatus $status): void;

    /** Freeze the current aggregates so later runs can be diffed against them. */
    public function recordSnapshot(
        Organization $organization,
        OrganizationData $data,
        ?ParseRun $run,
        CarbonImmutable $at,
    ): OrganizationSnapshot;

    public function delete(Organization $organization): void;
}
