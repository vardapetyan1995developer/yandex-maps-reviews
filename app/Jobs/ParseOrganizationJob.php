<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Repositories\OrganizationRepository;
use App\Contracts\Repositories\ParseRunRepository;
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

    public function handle(
        SourceRegistry $registry,
        OrganizationSyncService $sync,
        OrganizationRepository $organizations,
        ParseRunRepository $parseRuns,
    ): void {
        $organization = $organizations->findById($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        // The batch may have been cancelled while this job waited in the queue
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = $this->resolveRun($organization, $parseRuns);

        $organizations->updateStatus($organization, ParseStatus::Running);
        $parseRuns->markRunning($run, $this->attempts(), CarbonImmutable::now());

        try {
            $source = $registry->forKey($organization->source);
            $reference = $source->reference($organization->url);

            $result = $source->scrape($reference, $this->progressReporter($run, $parseRuns));
            $stats = $sync->sync($organization, $result, $run);

            $parseRuns->recordSuccess($run, $result, $stats, CarbonImmutable::now());

            Log::info('Organization parse finished', [
                'organization_id' => $organization->getKey(),
                'strategy' => $result->strategy,
                'reviews' => $result->reviewCount(),
                'created' => $stats->created,
                'updated' => $stats->updated,
                'disappeared' => $stats->disappeared,
                'truncated' => $result->truncated,
                'completeness' => round($result->completeness(), 3),
            ]);
        } catch (ScrapingException $e) {
            $this->handleFailure($organization, $run, $organizations, $parseRuns, $e->reason(), $e->getMessage(), $e->context());

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
                $organizations,
                $parseRuns,
                FailureReason::Unknown,
                $e->getMessage(),
                ['exception' => $e::class],
            );

            throw $e;
        }
    }

    /**
     * Progress is written to storage as collection proceeds so the interface
     * can show movement while the job is still running.
     *
     * @return callable(ScrapeProgress): void
     */
    private function progressReporter(ParseRun $run, ParseRunRepository $parseRuns): callable
    {
        return static function (ScrapeProgress $progress) use ($run, $parseRuns): void {
            $parseRuns->recordProgress($run, $progress);
        };
    }

    private function resolveRun(Organization $organization, ParseRunRepository $parseRuns): ParseRun
    {
        if ($this->parseRunId !== null) {
            $run = $parseRuns->findById($this->parseRunId);

            if ($run instanceof ParseRun) {
                return $run;
            }
        }

        return $parseRuns->start($organization, $this->attempts());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleFailure(
        Organization $organization,
        ParseRun $run,
        OrganizationRepository $organizations,
        ParseRunRepository $parseRuns,
        FailureReason $reason,
        string $message,
        array $context,
    ): void {
        $isLastAttempt = $this->attempts() >= $this->tries || ! $reason->isRetryable();

        $parseRuns->recordFailure($run, $reason, $message, $context, $this->attempts(), $isLastAttempt);

        if ($isLastAttempt) {
            $organizations->updateStatus($organization, ParseStatus::Failed);
        }

        // A schema change is the only cause that requires a code change, so it
        // is logged at error level and should page someone — unlike a routine
        // block or network failure
        $level = $reason->requiresDeveloperAttention() ? 'error' : 'warning';

        Log::log($level, 'Organization parse failed', [
            'organization_id' => $organization->getKey(),
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
        $organizations = app(OrganizationRepository::class);
        $organization = $organizations->findById($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        $organizations->updateStatus($organization, ParseStatus::Failed);

        app(ParseRunRepository::class)->failUnfinished(
            $organization,
            $exception?->getMessage() ?? 'Джоба завершилась аварийно',
            CarbonImmutable::now(),
        );
    }
}
