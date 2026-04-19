<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\SkipIfBatchCancelledTest;

use Carbon\CarbonImmutable;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\SkipIfBatchCancelled;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SkipIfBatchCancelledTest extends TestCase
{
    public function testJobsAreSkippedOnceBatchIsCancelled()
    {
        [$beforeCancelled] = (new SkipCancelledBatchableTestJob)->withFakeBatch();
        [$afterCancelled] = (new SkipCancelledBatchableTestJob)->withFakeBatch(
            cancelledAt: CarbonImmutable::now()
        );

        $this->assertJobRanSuccessfully($beforeCancelled);
        $this->assertJobWasSkipped($afterCancelled);
    }

    protected function assertJobRanSuccessfully($class): void
    {
        $this->assertJobHandled($class, true);
    }

    protected function assertJobWasSkipped($class): void
    {
        $this->assertJobHandled($class, false);
    }

    protected function assertJobHandled($class, bool $expectedHandledValue): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('uuid')->once()->andReturn('simple-test-uuid');
        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command = $class),
        ]);

        $this->assertEquals($expectedHandledValue, $class::$handled);
    }
}

class SkipCancelledBatchableTestJob
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }
}
