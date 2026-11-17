<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Jobs;

use Hypervel\Support\Facades\Bus;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Jobs\ProcessPendingUpdates;
use Hypervel\Tests\Telescope\FeatureTestCase;
use JMac\Testing\Double;

class ProcessPendingUpdatesTest extends FeatureTestCase
{
    public function testPendingUpdates(): void
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
            ['id' => 2, 'content' => 'bar'],
        ]);

        $failedUpdates = collect();
        $repository = Double::for(EntriesRepository::class)->strict();

        $repository
            ->expects('update')
            ->with($pendingUpdates)
            ->returns($failedUpdates);

        (new ProcessPendingUpdates($pendingUpdates))->handle($repository);

        Bus::assertNothingDispatched();
    }

    public function testPendingUpdatesMayStayPending(): void
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
            ['id' => 2, 'content' => 'bar'],
        ]);
        $failedUpdates = collect([
            $pendingUpdates->get(1),
        ]);

        $repository = Double::for(EntriesRepository::class)->strict();

        $repository
            ->expects('update')
            ->with($pendingUpdates)
            ->returns($failedUpdates);

        (new ProcessPendingUpdates($pendingUpdates))->handle($repository);

        Bus::assertDispatched(ProcessPendingUpdates::class, function ($job) {
            return $job->attempt === 1 && $job->pendingUpdates->toArray() === [['id' => 2, 'content' => 'bar']];
        });
    }

    public function testPendingUpdatesMayStayPendingOnlyThreeTimes(): void
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
            ['id' => 2, 'content' => 'bar'],
        ]);
        $failedUpdates = collect([
            $pendingUpdates->get(1),
        ]);

        $repository = Double::for(EntriesRepository::class)->strict();

        $repository
            ->expects('update')
            ->with($pendingUpdates)
            ->returns($failedUpdates);

        (new ProcessPendingUpdates($pendingUpdates, 2))->handle($repository);

        Bus::assertNothingDispatched();
    }

    public function testNullableCustomRepositoryResultDoesNotDispatchAnotherUpdate(): void
    {
        Bus::fake();

        $pendingUpdates = collect([
            ['id' => 1, 'content' => 'foo'],
        ]);

        $repository = Double::for(EntriesRepository::class)->strict();
        $repository->expects('update')
            ->with($pendingUpdates)
            ->returns(null);

        (new ProcessPendingUpdates($pendingUpdates))->handle($repository);

        Bus::assertNothingDispatched();
    }
}
