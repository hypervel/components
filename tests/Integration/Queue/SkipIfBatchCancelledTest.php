<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\SkipIfBatchCancelledTest;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\SkipIfBatchCancelled;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SkipIfBatchCancelledTest extends TestCase
{
    use VerifiesDoubles;

    public function testJobsAreSkippedOnceBatchIsCancelled(): void
    {
        [$beforeCancelled] = (new SkipCancelledBatchableTestJob)->withFakeBatch();
        [$afterCancelled] = (new SkipCancelledBatchableTestJob)->withFakeBatch(
            cancelledAt: CarbonImmutable::now()
        );

        $this->assertJobRanSuccessfully($beforeCancelled);
        $this->assertJobWasSkipped($afterCancelled);
    }

    /**
     * Assert the job runs.
     */
    protected function assertJobRanSuccessfully(SkipCancelledBatchableTestJob $class): void
    {
        $this->assertJobHandled($class, true);
    }

    /**
     * Assert the job is skipped.
     */
    protected function assertJobWasSkipped(SkipCancelledBatchableTestJob $class): void
    {
        $this->assertJobHandled($class, false);
    }

    /**
     * Assert whether the job is handled.
     */
    protected function assertJobHandled(SkipCancelledBatchableTestJob $class, bool $expectedHandledValue): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = Double::for(Job::class);

        $job->expects('uuid')->returns('simple-test-uuid');
        $job->expects('hasFailed')->returns(false);
        $job->allows('isReleased')->returns(false);
        $job->expects('isDeletedOrReleased')->returns(false);
        $job->expects('delete');

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

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;
    }

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }
}
