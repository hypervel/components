<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\SkipMiddlewareTest;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\Skip;
use Hypervel\Testbench\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;

class SkipMiddlewareTest extends TestCase
{
    public function testJobIsSkippedWhenConditionIsTrue(): void
    {
        $job = new SkipTestJob(skip: true);

        $this->assertJobWasSkipped($job);
    }

    public function testJobIsSkippedWhenConditionIsTrueUsingClosure(): void
    {
        $job = new SkipTestJob(skip: new SerializableClosure(fn (): bool => true));

        $this->assertJobWasSkipped($job);
    }

    public function testJobIsNotSkippedWhenConditionIsFalse(): void
    {
        $job = new SkipTestJob(skip: false);

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobIsNotSkippedWhenConditionIsFalseUsingClosure(): void
    {
        $job = new SkipTestJob(skip: new SerializableClosure(fn (): bool => false));

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobIsNotSkippedWhenConditionIsTrueWithUnless(): void
    {
        $job = new SkipTestJob(skip: true, useUnless: true);

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobIsNotSkippedWhenConditionIsTrueWithUnlessUsingClosure(): void
    {
        $job = new SkipTestJob(skip: new SerializableClosure(fn (): bool => true), useUnless: true);

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobIsSkippedWhenConditionIsFalseWithUnless(): void
    {
        $job = new SkipTestJob(skip: false, useUnless: true);

        $this->assertJobWasSkipped($job);
    }

    public function testJobIsSkippedWhenConditionIsFalseWithUnlessUsingClosure(): void
    {
        $job = new SkipTestJob(skip: new SerializableClosure(fn (): bool => false), useUnless: true);

        $this->assertJobWasSkipped($job);
    }

    /**
     * Assert the job runs.
     */
    protected function assertJobRanSuccessfully(SkipTestJob $class): void
    {
        $this->assertJobHandled(class: $class, expectedHandledValue: true);
    }

    /**
     * Assert the job is skipped.
     */
    protected function assertJobWasSkipped(SkipTestJob $class): void
    {
        $this->assertJobHandled(class: $class, expectedHandledValue: false);
    }

    /**
     * Assert whether the job is handled.
     */
    protected function assertJobHandled(SkipTestJob $class, bool $expectedHandledValue): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($class),
        ]);

        $this->assertEquals($expectedHandledValue, $class::$handled);
    }
}

class SkipTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    /**
     * Create a job with a skip condition.
     */
    public function __construct(
        protected bool|SerializableClosure $skip,
        protected bool $useUnless = false,
    ) {
    }

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
        $skip = $this->skip instanceof SerializableClosure
            ? $this->skip->getClosure()
            : $this->skip;

        if ($this->useUnless) {
            return [Skip::unless($skip)];
        }

        return [Skip::when($skip)];
    }
}
