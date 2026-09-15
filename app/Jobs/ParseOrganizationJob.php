<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ScrapeProgress;
use App\Enums\FailureReason;
use App\Enums\ParseStatus;
use App\Exceptions\Scraping\ScrapingException;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Services\Organizations\OrganizationSyncService;
use App\Services\Scraping\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background parse of a single organization.
 *
 * Doing this synchronously inside an HTTP request is not an option: a full run
 * means more than a dozen calls to an external source with pauses between them,
 * tens of seconds at best. For a chain of fifty branches such a request would
 * hit the timeout without fail.
 *
 * One job per organization. A branch network is a batch of these jobs: overall
 * progress is tracked per batch, and one failing branch does not bring down the
 * rest.
 */
final class ParseOrganizationJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * Retries exist for network failures and temporary blocks. Retrying a
     * "schema changed" error is pointless — those are filtered out separately
     * in handleFailure().
     */
    public int $tries = 3;

    /**
     * Time ceiling for one attempt. Derived from the maximum page count and the
     * pauses between them, with headroom.
     */
    public int $timeout = 600;

    /**
     * The uniqueness lock outlives the timeout so that, if a worker hangs, a
     * second job for the same organization cannot start in parallel.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $parseRunId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->organizationId))->expireAfter(900)];
    }

    /**
     * The pause before a retry grows exponentially: if the source has throttled
     * us, an immediate retry only makes things worse.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SourceRegistry $registry, OrganizationSyncService $sync): void
    {
        $organization = Organization::find($this->organizationId);

        if ($organization === null) {
            return;
        }

        // The batch may have been cancelled while this job waited in the queue
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = $this->resolveRun($organization);

        $organization->forceFill(['parse_status' => ParseStatus::Running])->save();
        $run->forceFill([
            'status' => ParseStatus::Running,
            'attempt' => $this->attempts(),
            'started_at' => CarbonImmutable::now(),
        ])->save();

        try {
            $source = $registry->forKey($organization->source);
            $reference = $source->reference($organization->url);

            $result = $source->scrape($reference, $this->progressReporter($run));

            $stats = $sync->sync($organization, $result, $run);

            $run->forceFill([
                'status' => $result->truncated ? ParseStatus::Partial : ParseStatus::Success,
                'strategy' => $result->strategy,
                'pages_fetched' => $result->pagesFetched,
                'reviews_found' => $result->reviewCount(),
                'reviews_created' => $stats['created'],
                'reviews_updated' => $stats['updated'],
                'truncated' => $result->truncated,
                'truncation_reason' => $result->truncationReason,
                'progress_percent' => 100,
                'finished_at' => CarbonImmutable::now(),
                'error_code' => null,
                'error_message' => null,
                'error_context' => null,
            ])->save();

            Log::info('Organization parse finished', [
                'organization_id' => $organization->id,
                'strategy' => $result->strategy,
                'reviews' => $result->reviewCount(),
                'created' => $stats['created'],
                'updated' => $stats['updated'],
                'disappeared' => $stats['disappeared'],
                'truncated' => $result->truncated,
                'completeness' => round($result->completeness(), 3),
            ]);
        } catch (ScrapingException $e) {
            $this->handleFailure($organization, $run, $e->reason(), $e->getMessage(), $e->context());

            // Drop non-retryable causes (schema change, broken link) from the
            // queue immediately: a retry will not fix them but will occupy a slot
            if (! $e->reason()->isRetryable()) {
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (Throwable $e) {
            $this->handleFailure(
                $organization,
                $run,
                FailureReason::Unknown,
                $e->getMessage(),
                ['exception' => $e::class],
            );

            throw $e;
        }
    }

    /**
     * Progress is written to the database as collection proceeds so the
     * interface can show movement while the job is still running.
     *
     * @return callable(ScrapeProgress): void
     */
    private function progressReporter(ParseRun $run): callable
    {
        return static function (ScrapeProgress $progress) use ($run): void {
            $run->forceFill([
                'pages_fetched' => $progress->pagesFetched,
                'reviews_found' => $progress->reviewsFetched,
                'progress_total' => $progress->totalExpected,
                'progress_percent' => $progress->percent(),
            ])->save();
        };
    }

    private function resolveRun(Organization $organization): ParseRun
    {
        if ($this->parseRunId !== null) {
            $run = ParseRun::find($this->parseRunId);

            if ($run !== null) {
                return $run;
            }
        }

        return $organization->parseRuns()->create([
            'status' => ParseStatus::Queued,
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleFailure(
        Organization $organization,
        ParseRun $run,
        FailureReason $reason,
        string $message,
        array $context,
    ): void {
        $isLastAttempt = $this->attempts() >= $this->tries || ! $reason->isRetryable();

        $run->forceFill([
            'status' => $isLastAttempt ? ParseStatus::Failed : ParseStatus::Queued,
            'error_code' => $reason,
            'error_message' => $message,
            'error_context' => $context,
            'attempt' => $this->attempts(),
            'finished_at' => $isLastAttempt ? CarbonImmutable::now() : null,
        ])->save();

        if ($isLastAttempt) {
            $organization->forceFill(['parse_status' => ParseStatus::Failed])->save();
        }

        // A schema change is the only cause that requires a code change, so it
        // is logged at error level and should page someone — unlike a routine
        // block or network failure
        $level = $reason->requiresDeveloperAttention() ? 'error' : 'warning';

        Log::log($level, 'Organization parse failed', [
            'organization_id' => $organization->id,
            'url' => $organization->url,
            'reason' => $reason->value,
            'message' => $message,
            'attempt' => $this->attempts(),
            'is_last_attempt' => $isLastAttempt,
            'context' => $context,
        ]);
    }

    /**
     * Called by the queue once attempts are exhausted. It duplicates the status
     * update because this point can be reached bypassing handle() entirely —
     * on a timeout, for instance.
     */
    public function failed(?Throwable $exception): void
    {
        $organization = Organization::find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $organization->forceFill(['parse_status' => ParseStatus::Failed])->save();

        $organization->parseRuns()
            ->whereIn('status', [ParseStatus::Queued->value, ParseStatus::Running->value])
            ->update([
                'status' => ParseStatus::Failed->value,
                'error_message' => $exception?->getMessage() ?? 'Джоба завершилась аварийно',
                'finished_at' => CarbonImmutable::now(),
            ]);
    }
}
