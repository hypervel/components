<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\WithoutOverlappingJobsTest;

use Exception;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\WithoutOverlapping;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class WithoutOverlappingJobsTest extends QueueTestCase
{
    public function testNonOverlappingJobsAreExecuted()
    {
        OverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command = new OverlappingTestJob()),
        ]);

        $lockKey = (new WithoutOverlapping())->getLockKey($command);

        $this->assertTrue(OverlappingTestJob::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($lockKey, 10)->acquire());
    }

    public function testLockIsReleasedOnJobExceptions()
    {
        FailedOverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);

        $this->expectException(Exception::class);

        try {
            $instance->call($job, [
                'command' => serialize($command = new FailedOverlappingTestJob()),
            ]);
        } finally {
            $lockKey = (new WithoutOverlapping())->getLockKey($command);

            $this->assertTrue(FailedOverlappingTestJob::$handled);
            $this->assertTrue($this->app->get(Cache::class)->lock($lockKey, 10)->acquire());
        }
    }

    public function testOverlappingJobsAreReleased()
    {
        OverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $lockKey = (new WithoutOverlapping())->getLockKey($command = new OverlappingTestJob());
        $this->app->get(Cache::class)->lock($lockKey, 10)->acquire();

        $job = m::mock(Job::class);

        $job->shouldReceive('release')->once();
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command),
        ]);

        $this->assertFalse(OverlappingTestJob::$handled);
    }

    public function testOverlappingJobsCanBeSkipped()
    {
        SkipOverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $lockKey = (new WithoutOverlapping())->getLockKey($command = new SkipOverlappingTestJob());
        $this->app->get(Cache::class)->lock($lockKey, 10)->acquire();

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command),
        ]);

        $this->assertFalse(SkipOverlappingTestJob::$handled);
    }

    public function testCanShareKeyAcrossJobs()
    {
        OverlappingTestJobWithSharedKeyOne::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $lockKey = (new WithoutOverlapping())->shared()->getLockKey(new OverlappingTestJobWithSharedKeyTwo());
        $this->app->get(Cache::class)->lock($lockKey, 10)->acquire();

        $job = m::mock(Job::class);

        $job->shouldReceive('release')->once();
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize(new OverlappingTestJobWithSharedKeyOne()),
        ]);

        $this->assertFalse(OverlappingTestJob::$handled);
    }

    public function testGetLock()
    {
        $job = new OverlappingTestJob();

        $this->assertSame(
            'laravel-queue-overlap:' . OverlappingTestJob::class . ':key',
            (new WithoutOverlapping('key'))->getLockKey($job)
        );

        $this->assertSame(
            'laravel-queue-overlap:key',
            (new WithoutOverlapping('key'))->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:Hypervel\Tests\Integration\Queue\WithoutOverlappingJobsTest\OverlappingTestJob:key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->shared()->getLockKey($job)
        );
    }

    public function testGetLockUsesDisplayName()
    {
        $job = new OverlappingTestJobWithDisplayName();

        $this->assertSame(
            'laravel-queue-overlap:App\Actions\WithoutOverlappingTestAction:key',
            (new WithoutOverlapping('key'))->getLockKey($job)
        );

        $this->assertSame(
            'laravel-queue-overlap:key',
            (new WithoutOverlapping('key'))->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:App\Actions\WithoutOverlappingTestAction:key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->shared()->getLockKey($job)
        );
    }
}

class OverlappingTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping()];
    }
}

class SkipOverlappingTestJob extends OverlappingTestJob
{
    public function middleware(): array
    {
        return [(new WithoutOverlapping())->dontRelease()];
    }
}

class FailedOverlappingTestJob extends OverlappingTestJob
{
    public function handle(): void
    {
        static::$handled = true;

        throw new Exception();
    }
}

class OverlappingTestJobWithSharedKeyOne
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping())->shared()];
    }
}

class OverlappingTestJobWithSharedKeyTwo
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping())->shared()];
    }
}

class OverlappingTestJobWithDisplayName extends OverlappingTestJob
{
    public function displayName(): string
    {
        return 'App\Actions\WithoutOverlappingTestAction';
    }
}
