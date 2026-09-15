<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReviewRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'review_id', 'parse_run_id',
        'old_rating', 'new_rating', 'old_text', 'new_text',
    ];

    protected function casts(): array
    {
        return [
            'old_rating' => 'integer',
            'new_rating' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
