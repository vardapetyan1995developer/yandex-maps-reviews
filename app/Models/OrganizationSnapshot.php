<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrganizationSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'parse_run_id',
        'name', 'rating', 'ratings_count', 'reviews_count', 'reviews_stored', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'reviews_stored' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The difference from the previous snapshot — a ready-made before/after for
     * the interface.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diffFrom(?self $previous): array
    {
        if ($previous === null) {
            return [];
        }

        $changes = [];

        foreach (['rating', 'ratings_count', 'reviews_count', 'reviews_stored'] as $field) {
            if ($previous->{$field} != $this->{$field}) {
                $changes[$field] = ['from' => $previous->{$field}, 'to' => $this->{$field}];
            }
        }

        return $changes;
    }
}
