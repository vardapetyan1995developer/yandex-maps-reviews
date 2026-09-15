<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ParseRunRepository;
use App\Data\ScrapeProgress;
use App\Data\ScrapeResult;
use App\Data\SyncStats;
use App\Enums\FailureReason;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\ParseRun;
use Carbon\CarbonImmutable;

final class EloquentParseRunRepository implements ParseRunRepository
{
    public function findById(int $id): ?ParseRun
    {
        return ParseRun::query()->find($id);
    }

    public function start(Organization $organization, int $attempt): ParseRun
    {
        $run = $this->queue($organization);

        if ($run->attempt !== $attempt) {
            $run->forceFill(['attempt' => $attempt])->save();
        }

        return $run;
    }

    /**
     * Obtain the pending run for a card, creating one only if none exists.
     *
     * Reuse rather than insert is essential here. The parse job is unique per
     * organization, so a second dispatch while one is already pending is
     * silently dropped by the queue. Creating a second row anyway would strand
     * it in `queued` forever — and since a card renders its most recent run, a
     * parse that actually succeeded would keep reading as "queued", with the
     * interface polling for progress that never arrives.
     */
    public function queue(Organization $organization): ParseRun
    {
        $pending = ParseRun::query()
            ->where('organization_id', '=', $organization->getKey())
            ->whereIn('status', [ParseStatus::Queued->value, ParseStatus::Running->value])
            ->orderByDesc('id')
            ->first();

        if ($pending instanceof ParseRun) {
            return $pending;
        }

        return ParseRun::query()->create([
            'organization_id' => $organization->getKey(),
            'status' => ParseStatus::Queued,
        ]);
    }

    public function markRunning(ParseRun $run, int $attempt, CarbonImmutable $startedAt): void
    {
        $run->forceFill([
            'status' => ParseStatus::Running,
            'attempt' => $attempt,
            'started_at' => $startedAt,
        ])->save();
    }

    public function recordProgress(ParseRun $run, ScrapeProgress $progress): void
    {
        $run->forceFill([
            'pages_fetched' => $progress->pagesFetched,
            'reviews_found' => $progress->reviewsFetched,
            'progress_total' => $progress->totalExpected,
            'progress_percent' => $progress->percent(),
        ])->save();
    }

    public function recordSuccess(
        ParseRun $run,
        ScrapeResult $result,
        SyncStats $stats,
        CarbonImmutable $finishedAt,
    ): void {
        $run->forceFill([
            'status' => $this->statusFor($result),
            'strategy' => $result->strategy,
            'pages_fetched' => $result->pagesFetched,
            'reviews_found' => $result->reviewCount(),
            'reviews_created' => $stats->created,
            'reviews_updated' => $stats->updated,
            'truncated' => $result->truncated,
            'truncation_reason' => $result->truncationReason,
            'progress_percent' => 100,
            'finished_at' => $finishedAt,
            // Cleared explicitly: a run that succeeded after earlier attempts
            // failed must not keep showing the old error
            'error_code' => null,
            'error_message' => null,
            'error_context' => null,
        ])->save();
    }

    public function recordFailure(
        ParseRun $run,
        FailureReason $reason,
        string $message,
        array $context,
        int $attempt,
        bool $isFinal,
    ): void {
        $run->forceFill([
            // A non-final failure returns to the queued state: the job will be
            // retried, and the interface should not present it as dead
            'status' => $isFinal ? ParseStatus::Failed : ParseStatus::Queued,
            'error_code' => $reason,
            'error_message' => $message,
            'error_context' => $context,
            'attempt' => $attempt,
            'finished_at' => $isFinal ? CarbonImmutable::now() : null,
        ])->save();
    }

    public function failUnfinished(Organization $organization, string $message, CarbonImmutable $at): int
    {
        return ParseRun::query()
            ->where('organization_id', '=', $organization->getKey())
            ->whereIn('status', [ParseStatus::Queued->value, ParseStatus::Running->value])
            ->update([
                'status' => ParseStatus::Failed->value,
                'error_message' => $message,
                'finished_at' => $at,
            ]);
    }

    public function statusFor(ScrapeResult $result): ParseStatus
    {
        // A truncated run is neither a success nor a failure: some data arrived,
        // and the interface has to say so rather than imply completeness
        return $result->truncated ? ParseStatus::Partial : ParseStatus::Success;
    }
}
