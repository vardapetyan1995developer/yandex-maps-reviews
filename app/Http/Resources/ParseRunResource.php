<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ParseRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ParseRun
 */
final class ParseRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_running' => $this->status->isRunning(),
            'strategy' => $this->strategy,
            'progress' => [
                'percent' => $this->progress_percent,
                'pages_fetched' => $this->pages_fetched,
                'reviews_found' => $this->reviews_found,
                'total_expected' => $this->progress_total,
            ],
            'result' => [
                'created' => $this->reviews_created,
                'updated' => $this->reviews_updated,
                'truncated' => $this->truncated,
                'truncation_reason' => $this->truncation_reason,
            ],
            'error' => $this->when($this->error_code !== null, fn (): array => [
                'code' => $this->error_code?->value,
                // The user sees the phrased cause, not the exception text:
                // technical detail stays in the logs
                'label' => $this->error_code?->label(),
                'message' => $this->error_message,
            ]),
            'attempt' => $this->attempt,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds(),
        ];
    }
}
