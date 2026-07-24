<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Support\Collection;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Support\Testing\Fakes\PendingBatchFake;
use Hypervel\Tests\TestCase;
use Mockery as m;

class PendingBatchFakeTest extends TestCase
{
    public function testHasJobsMatchesObjectsClassesAndTypedClosuresInOrder(): void
    {
        $batch = $this->batch([
            new PendingBatchFakeJob('first'),
            new PendingBatchFakeJob('second'),
            new PendingBatchFakeJob('third'),
        ]);

        $this->assertTrue($batch->hasJobs([
            new PendingBatchFakeJob('first'),
            PendingBatchFakeJob::class,
            fn (PendingBatchFakeJob $job): bool => $job->value === 'third',
        ]));
        $this->assertFalse($batch->hasJobs([
            new PendingBatchFakeJob('first'),
            new PendingBatchFakeJob('wrong'),
            new PendingBatchFakeJob('third'),
        ]));
        $this->assertFalse($batch->hasJobs([
            new PendingBatchFakeJob('first'),
            new PendingBatchFakeJob('second'),
        ]));
    }

    public function testJobsAreFilteredAndReindexed(): void
    {
        $first = new PendingBatchFakeJob('first');
        $second = new PendingBatchFakeJob('second');

        $batch = $this->batch([$first, null, false, $second]);

        $this->assertSame([$first, $second], $batch->jobs->all());
    }

    private function batch(array $jobs): PendingBatchFake
    {
        $bus = new BusFake(m::mock(QueueingDispatcher::class));

        return new PendingBatchFake($bus, new Collection($jobs));
    }
}

class PendingBatchFakeJob
{
    public function __construct(public string $value)
    {
    }
}
