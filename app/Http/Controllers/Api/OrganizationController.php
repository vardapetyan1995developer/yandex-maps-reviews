<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ParseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Services\Scraping\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages connected organization cards.
 *
 * The controller is deliberately thin: link parsing lives in the source, the
 * parse itself in a job, and persistence in the sync service. Only HTTP
 * responsibilities remain here — check authorisation, create the record, queue
 * the work.
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly SourceRegistry $registry) {}

    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()
            ->organizations()
            ->with('latestParseRun')
            ->latest()
            ->get();

        return response()->json([
            'data' => OrganizationResource::collection($organizations),
        ]);
    }

    /**
     * Connect a card and start collecting its data.
     *
     * Submitting the same link again does not create a second record but
     * updates the existing one and restarts the parse — the expected behaviour
     * of a save button, and part of the idempotency requirement.
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $url = (string) $request->validated('url');

        $source = $this->registry->forUrl($url);
        $reference = $source->reference($url);

        $organization = Organization::updateOrCreate(
            [
                'source' => $reference->source,
                'external_id' => $reference->externalId,
            ],
            [
                'user_id' => $request->user()->id,
                'slug' => $reference->slug,
                'url' => $reference->canonicalUrl,
                'parse_status' => ParseStatus::Queued,
            ],
        );

        $run = $organization->parseRuns()->create(['status' => ParseStatus::Queued]);

        ParseOrganizationJob::dispatch($organization->id, $run->id);

        return response()->json([
            'data' => OrganizationResource::make($organization->load('latestParseRun')),
            'message' => 'Карточка подключена, сбор данных запущен.',
        ], 201);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeAccess($request, $organization);

        return response()->json([
            'data' => OrganizationResource::make($organization->load('latestParseRun')),
        ]);
    }

    /**
     * Manual re-run of the parse.
     *
     * Useful to the user (refresh the data) and as a recovery path after a
     * source failure without having to recreate the card.
     */
    public function refresh(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeAccess($request, $organization);

        if ($organization->parse_status->isRunning()) {
            return response()->json([
                'message' => 'Сбор данных по этой организации уже идёт.',
                'data' => OrganizationResource::make($organization->load('latestParseRun')),
            ], 409);
        }

        $organization->forceFill(['parse_status' => ParseStatus::Queued])->save();
        $run = $organization->parseRuns()->create(['status' => ParseStatus::Queued]);

        ParseOrganizationJob::dispatch($organization->id, $run->id);

        return response()->json([
            'data' => OrganizationResource::make($organization->fresh()->load('latestParseRun')),
            'message' => 'Обновление запущено.',
        ], 202);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeAccess($request, $organization);

        $organization->delete();

        return response()->json(['message' => 'Карточка отключена.']);
    }

    /**
     * Only the user who connected a card can see it.
     *
     * 404 rather than 403 is deliberate: a 403 would confirm that someone
     * else's record exists.
     */
    private function authorizeAccess(Request $request, Organization $organization): void
    {
        abort_unless($organization->user_id === $request->user()->id, 404);
    }
}
