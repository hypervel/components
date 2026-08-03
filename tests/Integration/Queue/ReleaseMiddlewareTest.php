<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\Release;
use Hypervel\Testbench\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;

class ReleaseMiddlewareTest extends TestCase
{
    public function testJobIsReleasedWhenConditionIsTrue(): void
    {
        $job = new ReleaseTestJob(release: true, releaseAfter: 60);

        $this->assertJobWasReleased($job, releaseAfter: 60);
    }

    public function testJobIsReleasedWhenConditionIsTrueUsingClosure(): void
    {
        $job = new ReleaseTestJob(release: new SerializableClosure(fn () => true), releaseAfter: 60);

        $this->assertJobWasReleased($job, releaseAfter: 60);
    }

    public function testJobRunsWhenConditionIsFalse(): void
    {
        $job = new ReleaseTestJob(release: false);

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobRunsWhenConditionIsFalseUsingClosure(): void
    {
        $job = new ReleaseTestJob(release: new SerializableClosure(fn () => false));

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobIsReleasedWithoutDelayByDefault(): void
    {
        $job = new ReleaseTestJob(release: true);

        $this->assertJobWasReleased($job, releaseAfter: 0);
    }

    public function testJobRunsWhenConditionIsTrueWithUnless(): void
    {
        $job = new ReleaseTestJob(release: true, useUnless: true);

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobRunsWhenConditionIsTrueWithUnlessUsingClosure(): void
    {
        $job = new ReleaseTestJob(release: new SerializableClosure(fn () => true), useUnless: true);

        $this->assertJobRanSuccessfully($job);
    }

    public function testJobIsReleasedWhenConditionIsFalseWithUnless(): void
    {
        $job = new ReleaseTestJob(release: false, useUnless: true, releaseAfter: 60);

        $this->assertJobWasReleased($job, releaseAfter: 60);
    }

    public function testJobIsReleasedWhenConditionIsFalseWithUnlessUsingClosure(): void
    {
        $job = new ReleaseTestJob(release: new SerializableClosure(fn () => false), useUnless: true, releaseAfter: 60);

        $this->assertJobWasReleased($job, releaseAfter: 60);
    }

    protected function assertJobRanSuccessfully(ReleaseTestJob $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();
        $job->shouldReceive('release')->never();

        $instance->call($job, [
            'command' => serialize($class),
        ]);

        $this->assertTrue($class::$handled);
    }

    protected function assertJobWasReleased(ReleaseTestJob $class, int $releaseAfter = 0): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(true);
        $job->shouldReceive('release')->once()->with($releaseAfter);
        $job->shouldReceive('delete')->never();

        $instance->call($job, [
            'command' => serialize($class),
        ]);

        $this->assertFalse($class::$handled);
    }
}

class ReleaseTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function __construct(
        protected bool|SerializableClosure $release,
        protected bool $useUnless = false,
        protected int $releaseAfter = 0,
    ) {
    }

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        $release = $this->release instanceof SerializableClosure
            ? $this->release->getClosure()
            : $this->release;

        if ($this->useUnless) {
            return [Release::unless($release, $this->releaseAfter)];
        }

        return [Release::when($release, $this->releaseAfter)];
    }
}
