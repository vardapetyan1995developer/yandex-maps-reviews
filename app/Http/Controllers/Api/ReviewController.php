<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paginated review listing.
 *
 * Reviews are served from our own database rather than re-parsed on every
 * request. This is a deliberate decision: one pass over a card is more than a
 * dozen calls to Yandex, and making them on every page change would mean
 * seconds of waiting in the interface and a rate-limit block within the first
 * minutes of use. The parse runs once in the background; the interface works
 * against the cache in the database.
 */
final class ReviewController extends Controller
{
    private const PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($organization->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'sort' => ['sometimes', 'string', 'in:date_desc,date_asc,rating_desc,rating_asc'],
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? self::PER_PAGE);

        $query = $organization->reviews()
            // The revision count marks edited reviews; withCount rather than
            // loading the revisions themselves, of which there may be many
            ->withCount('revisions')
            ->whereNull('disappeared_at');

        if (isset($validated['rating'])) {
            $query->where('rating', $validated['rating']);
        }

        $this->applySorting($query, $validated['sort'] ?? 'date_desc');

        $reviews = $query->paginate($perPage)->withQueryString();

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

    private function applySorting(mixed $query, string $sort): void
    {
        match ($sort) {
            'date_asc' => $query->orderBy('published_at')->orderBy('id'),
            'rating_desc' => $query->orderByDesc('rating')->orderByDesc('published_at'),
            'rating_asc' => $query->orderBy('rating')->orderByDesc('published_at'),
            // The secondary sort by id is mandatory: reviews can share a
            // timestamp, and without it the ordering drifts between pages, so
            // the same record can appear on two of them
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }
}
