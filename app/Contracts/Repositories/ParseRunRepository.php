<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Data\ScrapeProgress;
use App\Data\ScrapeResult;
use App\Data\SyncStats;
use App\Enums\FailureReason;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\ParseRun;
use Carbon\CarbonImmutable;

/**
 * Persistence for the parse-run log.
 *
 * This table is written to from inside a long-running job, so the operations
 * are deliberately fine-grained: progress is flushed repeatedly while a run is
 * still in flight, and the outcome is recorded once at the end.
 */
interface ParseRunRepository
{
    public function findById(int $id): ?ParseRun;

    public function start(Organization $organization, int $attempt): ParseRun;

    public function queue(Organization $organization): ParseRun;

    public function markRunning(ParseRun $run, int $attempt, CarbonImmutable $startedAt): void;

    /** Flush a progress snapshot so the interface can show movement mid-run. */
    public function recordProgress(ParseRun $run, ScrapeProgress $progress): void;

    public function recordSuccess(
        ParseRun $run,
        ScrapeResult $result,
        SyncStats $stats,
        CarbonImmutable $finishedAt,
    ): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordFailure(
        ParseRun $run,
        FailureReason $reason,
        string $message,
        array $context,
        int $attempt,
        bool $isFinal,
    ): void;

    /** Close out any runs left hanging when a job died outside its normal path. */
    public function failUnfinished(Organization $organization, string $message, CarbonImmutable $at): int;

    public function statusFor(ScrapeResult $result): ParseStatus;
}
