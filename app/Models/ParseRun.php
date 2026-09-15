<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureReason;
use App\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParseRun extends Model
{
    protected $fillable = [
        'organization_id', 'status', 'strategy',
        'pages_fetched', 'reviews_found', 'reviews_created', 'reviews_updated',
        'progress_total', 'progress_percent',
        'truncated', 'truncation_reason',
        'error_code', 'error_message', 'error_context',
        'attempt', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParseStatus::class,
            'error_code' => FailureReason::class,
            'error_context' => 'array',
            'truncated' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        // Carbon 3 returns a fractional number of seconds — cast explicitly,
        // otherwise the ?int signature is violated by any sub-second run
        return (int) round($this->started_at->diffInSeconds($this->finished_at));
    }
}
