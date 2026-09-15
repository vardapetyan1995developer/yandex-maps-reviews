<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
final class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'author' => [
                'name' => $this->author_name,
                'avatar' => $this->author_avatar,
            ],
            'rating' => $this->rating,
            'text' => $this->text,
            'published_at' => $this->published_at?->toIso8601String(),
            'is_edited' => $this->revisions_count > 0,
            'is_gone' => $this->disappeared_at !== null,
        ];
    }
}
