<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\OrganizationRepository;
use App\Contracts\Repositories\ReviewRepository;
use App\Data\ReviewQuery;
use App\Enums\ReviewSort;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paginated review listing.
 *
 * Reviews are served from our own storage rather than re-parsed on every
 * request. This is a deliberate decision: one pass over a card is more than a
 * dozen calls to Yandex, and making them on every page change would mean
 * seconds of waiting in the interface and a rate-limit block within the first
 * minutes of use. The parse runs once in the background; the interface works
 * against what was persisted.
 */
final class ReviewController extends Controller
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly ReviewRepository $reviews,
    ) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $card = $this->organizations->findForUser($organization, $request->user());

        // 404 rather than 403: a 403 would confirm someone else's record exists
        abort_unless($card instanceof Organization, 404);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ReviewQuery::MAX_PER_PAGE],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', ReviewSort::values())],
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $reviews = $this->reviews->paginateVisible($card, ReviewQuery::fromArray($validated));

        return response()->json([
            'data' => ReviewResource::collection($reviews->items()),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'from' => $reviews->firstItem(),
                'to' => $reviews->lastItem(),
            ],
        ]);
    }
}
