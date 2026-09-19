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

class WithoutOverlappingJobsTest extends QueueTestCase
{
    public function testNonOverlappingJobsAreExecuted(): void
    {
        OverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($command = new OverlappingTestJob),
        ]);

        $lockKey = (new WithoutOverlapping)->getLockKey($command);

        $this->assertTrue(OverlappingTestJob::$handled);
        $this->assertTrue($this->app->get(Cache::class)->lock($lockKey, 10)->acquire());
    }

    public function testLockIsReleasedOnJobExceptions(): void
    {
        FailedOverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $this->expectException(Exception::class);

        try {
            $instance->call($job, [
                'command' => serialize($command = new FailedOverlappingTestJob),
            ]);
        } finally {
            $lockKey = (new WithoutOverlapping)->getLockKey($command);

            $this->assertTrue(FailedOverlappingTestJob::$handled);
            $this->assertTrue($this->app->get(Cache::class)->lock($lockKey, 10)->acquire());
        }
    }

    public function testOverlappingJobsAreReleased(): void
    {
        OverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $lockKey = (new WithoutOverlapping)->getLockKey($command = new OverlappingTestJob);
        $this->app->get(Cache::class)->lock($lockKey, 10)->acquire();

        $job = m::mock(Job::class);

        $job->expects('release');
        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command),
        ]);

        $this->assertFalse(OverlappingTestJob::$handled);
    }

    public function testOverlappingJobsCanBeSkipped(): void
    {
        SkipOverlappingTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $lockKey = (new WithoutOverlapping)->getLockKey($command = new SkipOverlappingTestJob);
        $this->app->get(Cache::class)->lock($lockKey, 10)->acquire();

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($command),
        ]);

        $this->assertFalse(SkipOverlappingTestJob::$handled);
    }

    public function testCanShareKeyAcrossJobs(): void
    {
        OverlappingTestJobWithSharedKeyOne::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $lockKey = (new WithoutOverlapping)->shared()->getLockKey(new OverlappingTestJobWithSharedKeyTwo);
        $this->app->get(Cache::class)->lock($lockKey, 10)->acquire();

        $job = m::mock(Job::class);

        $job->expects('release');
        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize(new OverlappingTestJobWithSharedKeyOne),
        ]);

        $this->assertFalse(OverlappingTestJobWithSharedKeyOne::$handled);
    }

    public function testGetLock(): void
    {
        $job = new OverlappingTestJob;

        $this->assertSame(
            'laravel-queue-overlap:' . OverlappingTestJob::class . ':key',
            (new WithoutOverlapping('key'))->getLockKey($job)
        );

        $this->assertSame(
            'laravel-queue-overlap:key',
            (new WithoutOverlapping('key'))->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:' . OverlappingTestJob::class . ':key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->shared()->getLockKey($job)
        );
    }

    public function testGetLockUsesDisplayName(): void
    {
        $job = new OverlappingTestJobWithDisplayName;

        $this->assertSame(
            'laravel-queue-overlap:' . hash('xxh128', 'App\Actions\WithoutOverlappingTestAction') . ':key',
            (new WithoutOverlapping('key'))->getLockKey($job)
        );

        $this->assertSame(
            'laravel-queue-overlap:key',
            (new WithoutOverlapping('key'))->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:' . hash('xxh128', 'App\Actions\WithoutOverlappingTestAction') . ':key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:key',
            (new WithoutOverlapping('key'))->withPrefix('prefix:')->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:' . hash('xxh128', 'App\Actions\WithoutOverlappingTestAction') . ':unit',
            (new WithoutOverlapping(UnitCategory::unit))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:unit',
            (new WithoutOverlapping(UnitCategory::unit))->withPrefix('prefix:')->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:' . hash('xxh128', 'App\Actions\WithoutOverlappingTestAction') . ':backed',
            (new WithoutOverlapping(BackedCategory::backed))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:backed',
            (new WithoutOverlapping(BackedCategory::backed))->withPrefix('prefix:')->shared()->getLockKey($job)
        );

        $this->assertSame(
            'prefix:' . hash('xxh128', 'App\Actions\WithoutOverlappingTestAction') . ':0',
            (new WithoutOverlapping(IntBackedCategory::Zero))->withPrefix('prefix:')->getLockKey($job)
        );

        $this->assertSame(
            'prefix:0',
            (new WithoutOverlapping(IntBackedCategory::Zero))->withPrefix('prefix:')->shared()->getLockKey($job)
        );
    }
}

class OverlappingTestJob
{
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
        return [new WithoutOverlapping];
    }
}

class SkipOverlappingTestJob extends OverlappingTestJob
{
    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping)->dontRelease()];
    }
}

class FailedOverlappingTestJob extends OverlappingTestJob
{
    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;

        throw new Exception;
    }
}

class OverlappingTestJobWithSharedKeyOne
{
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
        return [(new WithoutOverlapping)->shared()];
    }
}

class OverlappingTestJobWithSharedKeyTwo
{
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
        return [(new WithoutOverlapping)->shared()];
    }
}

class OverlappingTestJobWithDisplayName extends OverlappingTestJob
{
    /**
     * Get the job display name.
     */
    public function displayName(): string
    {
        return 'App\Actions\WithoutOverlappingTestAction';
    }
}

enum UnitCategory
{
    case unit;
}

enum BackedCategory: string
{
    case backed = 'backed';
}

enum IntBackedCategory: int
{
    case Zero = 0;
}
