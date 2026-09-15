<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\OrganizationRepository;
use App\Contracts\Repositories\ParseRunRepository;
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
 * parse itself in a job, and persistence behind the repositories. Only HTTP
 * responsibilities remain here — check authorisation, delegate, queue the work.
 */
final class OrganizationController extends Controller
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly OrganizationRepository $organizations,
        private readonly ParseRunRepository $parseRuns,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => OrganizationResource::collection(
                $this->organizations->allForUser($request->user()),
            ),
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

        $reference = $this->registry->forUrl($url)->reference($url);
        $organization = $this->organizations->connect($request->user(), $reference);
        $run = $this->parseRuns->queue($organization);

        ParseOrganizationJob::dispatch($organization->getKey(), $run->getKey());

        return response()->json([
            'data' => OrganizationResource::make($organization->load('latestParseRun')),
            'message' => 'Карточка подключена, сбор данных запущен.',
        ], 201);
    }

    public function show(Request $request, int $organization): JsonResponse
    {
        return response()->json([
            'data' => OrganizationResource::make($this->authorized($request, $organization)),
        ]);
    }

    /**
     * Manual re-run of the parse.
     *
     * Useful to the user (refresh the data) and as a recovery path after a
     * source failure without having to recreate the card.
     */
    public function refresh(Request $request, int $organization): JsonResponse
    {
        $card = $this->authorized($request, $organization);

        if ($card->parse_status->isRunning()) {
            return response()->json([
                'message' => 'Сбор данных по этой организации уже идёт.',
                'data' => OrganizationResource::make($card),
            ], 409);
        }

        $this->organizations->updateStatus($card, ParseStatus::Queued);
        $run = $this->parseRuns->queue($card);

        ParseOrganizationJob::dispatch($card->getKey(), $run->getKey());

        return response()->json([
            'data' => OrganizationResource::make($card->fresh()->load('latestParseRun')),
            'message' => 'Обновление запущено.',
        ], 202);
    }

    public function destroy(Request $request, int $organization): JsonResponse
    {
        $this->organizations->delete($this->authorized($request, $organization));

        return response()->json(['message' => 'Карточка отключена.']);
    }

    /**
     * Resolve a card that belongs to the current user, or abort.
     *
     * 404 rather than 403 is deliberate: a 403 would confirm that someone
     * else's record exists.
     */
    private function authorized(Request $request, int $id): Organization
    {
        $card = $this->organizations->findForUser($id, $request->user());

        abort_unless($card instanceof Organization, 404);

        return $card;
    }
}
