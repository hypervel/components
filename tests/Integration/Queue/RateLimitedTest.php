<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\RateLimitedTest;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\RateLimiter\Unlimited;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class RateLimitedTest extends TestCase
{
    public function testUnlimitedJobsAreExecuted(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (object $job): Unlimited {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
    }

    public function testUnlimitedJobsAreExecutedUsingBackedEnum(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for(BackedEnumNamedRateLimited::Foo, function (object $job): Unlimited {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingBackedEnum::class);
        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingBackedEnum::class);
    }

    public function testUnlimitedJobsAreExecutedUsingUnitEnum(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for(UnitEnumNamedRateLimited::hypervel, function (object $job): Unlimited {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingUnitEnum::class);
        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingUnitEnum::class);
    }

    // REMOVED: the cache-specific multi-call fixture is replaced by the atomic
    // package-store decision exercised by testRateLimitedJobsAreNotExecutedOnLimitReached().

    public function testRateLimitedJobsAreNotExecutedOnLimitReached(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (object $job): Limit {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
        $this->assertJobWasReleased(RateLimitedTestJob::class);
    }

    public function testSlidingWindowRetryDelayIsUsedWhenReleasingAJob(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
        $rateLimiter = $this->app->make(RateLimiter::class);
        $rateLimiter->for('test', fn (): SlidingWindow => SlidingWindow::perSecond(1, 2));

        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
        $this->assertJobWasReleasedAfter(RateLimitedTestJob::class, 6);
    }

    public function testRateLimitedJobsCanBeSkippedOnLimitReached(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (object $job): Limit {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedDontReleaseTestJob::class);
        $this->assertJobWasSkipped(RateLimitedDontReleaseTestJob::class);
    }

    public function testJobsCanHaveConditionalRateLimits(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (AdminTestJob|NonAdminTestJob $job): Limit|Unlimited {
            if ($job->isAdmin()) {
                return Limit::none();
            }

            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(AdminTestJob::class);
        $this->assertJobRanSuccessfully(AdminTestJob::class);

        $this->assertJobRanSuccessfully(NonAdminTestJob::class);
        $this->assertJobWasReleased(NonAdminTestJob::class);
    }

    public function testRateLimitedJobsCanBeSkippedOnLimitReachedAndReleasedAfter(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (object $job): Limit {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedReleaseAfterTestJob::class);
        $this->assertJobWasReleasedAfter(RateLimitedReleaseAfterTestJob::class, 60);
    }

    public function testExplicitZeroReleaseDelayIsRespected(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (object $job): Limit {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedZeroReleaseAfterTestJob::class);
        $this->assertJobWasReleasedAfter(RateLimitedZeroReleaseAfterTestJob::class, 0);
    }

    public function testMiddlewareSerialization(): void
    {
        $rateLimited = new RateLimited('limiterName');
        $rateLimited->shouldRelease = false;

        $restoredRateLimited = unserialize(serialize($rateLimited));

        $fetch = (function (string $name): mixed {
            return $this->{$name};
        })->bindTo($restoredRateLimited, RateLimited::class);

        $this->assertFalse($restoredRateLimited->shouldRelease);
        $this->assertSame('limiterName', $fetch('limiterName'));
        $this->assertNull($fetch('storeName'));
        $this->assertInstanceOf(RateLimiter::class, $fetch('limiter'));
    }

    public function testReleaseAfterIsPreservedThroughSerialization(): void
    {
        $rateLimited = (new RateLimited('limiterName'))->releaseAfter(120);

        $restoredRateLimited = unserialize(serialize($rateLimited));

        $this->assertSame(120, $restoredRateLimited->releaseAfter);
    }

    public function testCustomReleaseAfterIsRespectedWhenMiddlewareIsStoredAsJobProperty(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function (object $job): Limit {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedSerializedPropertyTestJob::class);
        $this->assertJobWasReleasedAfter(RateLimitedSerializedPropertyTestJob::class, 60);
    }

    /**
     * Assert the job runs and is deleted.
     *
     * @param class-string $class
     */
    protected function assertJobRanSuccessfully(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($class::$handled);
    }

    /**
     * Assert the job is released without running.
     *
     * @param class-string $class
     */
    protected function assertJobWasReleased(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('release');
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertFalse($class::$handled);
    }

    /**
     * Assert the job is released with the given delay.
     *
     * @param class-string $class
     */
    protected function assertJobWasReleasedAfter(string $class, int $releaseAfter): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('release')->withArgs([$releaseAfter]);
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertFalse($class::$handled);
    }

    /**
     * Assert the job is deleted without running.
     *
     * @param class-string $class
     */
    protected function assertJobWasSkipped(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertFalse($class::$handled);
    }

    public function testItCanLimitPerMinute(): void
    {
        $limiter = $this->app->make(RateLimiter::class);
        $limiter->for('test', fn (): Limit => Limit::perMinute(3));
        $jobFactory = fn (): object => new class {
            public bool $released = false;

            /**
             * Mark the job as released.
             */
            public function release(): void
            {
                $this->released = true;
            }
        };
        $next = fn (object $job): object => $job;

        $middleware = new RateLimited('test');

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 3; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertFalse($job->released);

            CarbonImmutable::setTestNow(now()->addSeconds(1));
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        CarbonImmutable::setTestNow('2000-00-00 00:00:59.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        CarbonImmutable::setTestNow('2000-00-00 00:01:00.000');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertFalse($job->released);
    }

    public function testItCanLimitPerSecond(): void
    {
        $limiter = $this->app->make(RateLimiter::class);
        $limiter->for('test', fn (): Limit => Limit::perSecond(3));
        $jobFactory = fn (): object => new class {
            public bool $released = false;

            /**
             * Mark the job as released.
             */
            public function release(): void
            {
                $this->released = true;
            }
        };
        $next = fn (object $job): object => $job;

        $middleware = new RateLimited('test');

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 3; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertFalse($job->released);

            CarbonImmutable::setTestNow(now()->addMilliseconds(100));
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        CarbonImmutable::setTestNow('2000-00-00 00:00:01.000');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertFalse($job->released);
    }
}

class RateLimitedTestJob
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
        return [new RateLimited('test')];
    }
}

class AdminTestJob extends RateLimitedTestJob
{
    /**
     * Determine whether the job runs as an administrator.
     */
    public function isAdmin(): bool
    {
        return true;
    }
}

class NonAdminTestJob extends RateLimitedTestJob
{
    /**
     * Determine whether the job runs as an administrator.
     */
    public function isAdmin(): bool
    {
        return false;
    }
}

class RateLimitedDontReleaseTestJob extends RateLimitedTestJob
{
    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new RateLimited('test'))->dontRelease()];
    }
}

class RateLimitedReleaseAfterTestJob extends RateLimitedTestJob
{
    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new RateLimited('test'))->releaseAfter(60)];
    }
}

class RateLimitedZeroReleaseAfterTestJob extends RateLimitedTestJob
{
    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new RateLimited('test'))->releaseAfter(0)];
    }
}

class RateLimitedSerializedPropertyTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    /**
     * Create a job with rate-limiting middleware.
     */
    public function __construct()
    {
        $this->through([(new RateLimited('test'))->releaseAfter(60)]);
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
        return [];
    }
}

enum BackedEnumNamedRateLimited: string
{
    case Foo = 'bar';
}

enum UnitEnumNamedRateLimited
{
    case hypervel;
}

class RateLimitedTestJobUsingBackedEnum
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
        return [new RateLimited(BackedEnumNamedRateLimited::Foo)];
    }
}

class RateLimitedTestJobUsingUnitEnum
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
        return [new RateLimited(UnitEnumNamedRateLimited::hypervel)];
    }
}
