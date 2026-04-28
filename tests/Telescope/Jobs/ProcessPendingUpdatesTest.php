<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Jobs;

use Hypervel\Support\Facades\Bus;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Jobs\ProcessPendingUpdates;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

class ProcessPendingUpdatesTest extends FeatureTestCase
{
    public function testPendingUpdates()
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
            ['id' => 2, 'content' => 'bar'],
        ]);

        $failedUpdates = collect();
        $repository = m::mock(EntriesRepository::class);

        $repository
            ->shouldReceive('update')
            ->once()
            ->with($pendingUpdates)
            ->andReturn($failedUpdates);

        (new ProcessPendingUpdates($pendingUpdates))->handle($repository);

        Bus::assertNothingDispatched();
    }

    public function testPendingUpdatesMayStayPending()
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
            ['id' => 2, 'content' => 'bar'],
        ]);
        $failedUpdates = collect([
            $pendingUpdates->get(1),
        ]);

        $repository = m::mock(EntriesRepository::class);

        $repository
            ->shouldReceive('update')
            ->once()
            ->with($pendingUpdates)
            ->andReturn($failedUpdates);

        (new ProcessPendingUpdates($pendingUpdates))->handle($repository);

        Bus::assertDispatched(ProcessPendingUpdates::class, function ($job) {
            return $job->attempt == 1 && $job->pendingUpdates->toArray() == [['id' => 2, 'content' => 'bar']];
        });
    }

    public function testPendingUpdatesMayStayPendingOnlyThreeTimes()
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
            ['id' => 2, 'content' => 'bar'],
        ]);
        $failedUpdates = collect([
            $pendingUpdates->get(1),
        ]);

        $repository = m::mock(EntriesRepository::class);

        $repository
            ->shouldReceive('update')
            ->once()
            ->with($pendingUpdates)
            ->andReturn($failedUpdates);

        (new ProcessPendingUpdates($pendingUpdates, 2))->handle($repository);

        Bus::assertNothingDispatched();
    }
}
