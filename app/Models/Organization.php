<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParseStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'source', 'external_id', 'slug', 'url',
        'name', 'address', 'categories',
        'rating', 'ratings_count', 'reviews_count', 'reviews_stored',
        'parse_status', 'last_parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'reviews_stored' => 'integer',
            'parse_status' => ParseStatus::class,
            'last_parsed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function parseRuns(): HasMany
    {
        return $this->hasMany(ParseRun::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }

    /** The latest parse run — the source of status and progress for the interface. */
    public function latestParseRun(): HasOne
    {
        return $this->hasOne(ParseRun::class)->latestOfMany();
    }

    /**
     * Whether we collected fewer reviews than the platform claims to have.
     *
     * Almost always true for large businesses, because the source limits output
     * depth. The interface must surface this, otherwise the user will assume
     * they are seeing every review.
     */
    public function isPartial(): bool
    {
        return $this->reviews_stored < $this->reviews_count;
    }
}
