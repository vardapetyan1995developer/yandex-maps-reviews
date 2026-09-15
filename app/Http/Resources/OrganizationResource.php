<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'url' => $this->url,
            'name' => $this->name,
            'address' => $this->address,
            'categories' => $this->categories ?? [],

            'rating' => $this->rating,

            // The two counters are exposed separately and never summed: a
            // star rating without text and a written review are different things
            'ratings_count' => $this->ratings_count,
            'reviews_count' => $this->reviews_count,

            // How many reviews were actually collected. The interface surfaces
            // any divergence from reviews_count explicitly so the numbers do
            // not look contradictory
            'reviews_stored' => $this->reviews_stored,
            'is_partial' => $this->isPartial(),

            'parse_status' => $this->parse_status->value,
            'parse_status_label' => $this->parse_status->label(),
            'last_parsed_at' => $this->last_parsed_at?->toIso8601String(),

            'latest_parse_run' => ParseRunResource::make(
                $this->whenLoaded('latestParseRun'),
            ),
        ];
    }
}
