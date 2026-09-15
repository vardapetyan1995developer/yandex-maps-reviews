<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Contracts\Repositories\ParseRunRepository;
use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\ParseRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ParseRunRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ParseRunRepository $repository;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ParseRunRepository::class);
        $this->organization = Organization::factory()->create();
    }

    #[Test]
    public function queueing_twice_reuses_the_pending_run_instead_of_orphaning_one(): void
    {
        // The parse job is unique per organization, so a second dispatch while
        // one is pending is dropped by the queue. If a second run row were
        // created anyway it would sit in `queued` forever, and because a card
        // renders its most recent run, a successful parse would still read as
        // queued in the interface.
        $first = $this->repository->queue($this->organization);
        $second = $this->repository->queue($this->organization);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ParseRun::query()->count());
    }

    #[Test]
    public function a_running_run_is_reused_too(): void
    {
        $run = $this->repository->queue($this->organization);
        $this->repository->markRunning($run, 1, now()->toImmutable());

        $this->assertSame($run->getKey(), $this->repository->queue($this->organization)->getKey());
        $this->assertSame(1, ParseRun::query()->count());
    }

    #[Test]
    public function a_finished_run_does_not_block_a_new_one(): void
    {
        $finished = $this->repository->queue($this->organization);
        $finished->forceFill(['status' => ParseStatus::Success])->save();

        $fresh = $this->repository->queue($this->organization);

        $this->assertNotSame($finished->getKey(), $fresh->getKey());
        $this->assertSame(2, ParseRun::query()->count());
    }

    #[Test]
    public function pending_runs_of_other_organizations_are_not_reused(): void
    {
        $other = Organization::factory()->create();
        $otherRun = $this->repository->queue($other);

        $mine = $this->repository->queue($this->organization);

        $this->assertNotSame($otherRun->getKey(), $mine->getKey());
    }

    #[Test]
    public function an_unfinished_run_is_closed_out_when_a_job_dies(): void
    {
        $this->repository->queue($this->organization);

        $closed = $this->repository->failUnfinished(
            $this->organization,
            'Джоба завершилась аварийно',
            now()->toImmutable(),
        );

        $this->assertSame(1, $closed);
        $this->assertSame(ParseStatus::Failed, ParseRun::query()->first()->status);
    }
}
