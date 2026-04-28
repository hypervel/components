<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\RateLimitedTest;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class RateLimitedTest extends TestCase
{
    public function testUnlimitedJobsAreExecuted()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function ($job) {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
    }

    public function testUnlimitedJobsAreExecutedUsingBackedEnum()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for(BackedEnumNamedRateLimited::Foo, function ($job) {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingBackedEnum::class);
        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingBackedEnum::class);
    }

    public function testUnlimitedJobsAreExecutedUsingUnitEnum()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for(UnitEnumNamedRateLimited::hypervel, function ($job) {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingUnitEnum::class);
        $this->assertJobRanSuccessfully(RateLimitedTestJobUsingUnitEnum::class);
    }

    public function testRateLimitedJobsAreNotExecutedOnLimitReached2()
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(0, 1, null);
        $cache->shouldReceive('add')->andReturn(true, true);
        $cache->shouldReceive('increment')->andReturn(1);
        $cache->shouldReceive('has')->andReturn(true);
        $cache->shouldReceive('getStore')->andReturn(new ArrayStore);

        $rateLimiter = new RateLimiter($cache);
        $this->app->instance(RateLimiter::class, $rateLimiter);
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function ($job) {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);

        // Assert Job was released and released with a delay greater than 0
        RateLimitedTestJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->once()->withArgs(function ($delay) {
            return $delay >= 0;
        });
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new RateLimitedTestJob),
        ]);

        $this->assertFalse(RateLimitedTestJob::$handled);
    }

    public function testRateLimitedJobsAreNotExecutedOnLimitReached()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function ($job) {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedTestJob::class);
        $this->assertJobWasReleased(RateLimitedTestJob::class);
    }

    public function testRateLimitedJobsCanBeSkippedOnLimitReached()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function ($job) {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedDontReleaseTestJob::class);
        $this->assertJobWasSkipped(RateLimitedDontReleaseTestJob::class);
    }

    public function testJobsCanHaveConditionalRateLimits()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function ($job) {
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

    public function testRateLimitedJobsCanBeSkippedOnLimitReachedAndReleasedAfter()
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for('test', function ($job) {
            return Limit::perHour(1);
        });

        $this->assertJobRanSuccessfully(RateLimitedReleaseAfterTestJob::class);
        $this->assertJobWasReleasedAfter(RateLimitedReleaseAfterTestJob::class, 60);
    }

    public function testMiddlewareSerialization()
    {
        $rateLimited = new RateLimited('limiterName');
        $rateLimited->shouldRelease = false;

        $restoredRateLimited = unserialize(serialize($rateLimited));

        $fetch = (function (string $name) {
            return $this->{$name};
        })->bindTo($restoredRateLimited, RateLimited::class);

        $this->assertFalse($restoredRateLimited->shouldRelease);
        $this->assertSame('limiterName', $fetch('limiterName'));
        $this->assertInstanceOf(RateLimiter::class, $fetch('limiter'));
    }

    protected function assertJobRanSuccessfully(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($class::$handled);
    }

    protected function assertJobWasReleased(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->once();
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertFalse($class::$handled);
    }

    protected function assertJobWasReleasedAfter(string $class, int $releaseAfter): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->once()->withArgs([$releaseAfter]);
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertFalse($class::$handled);
    }

    protected function assertJobWasSkipped(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertFalse($class::$handled);
    }

    public function testItCanLimitPerMinute()
    {
        Container::getInstance()->instance(RateLimiter::class, $limiter = new RateLimiter(new Repository(new ArrayStore)));
        $limiter->for('test', fn () => Limit::perMinute(3));
        $jobFactory = fn () => new class {
            public $released = false;

            public function release()
            {
                $this->released = true;
            }
        };
        $next = fn ($job) => $job;

        $middleware = new RateLimited('test');

        Carbon::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 3; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertFalse($job->released);

            Carbon::setTestNow(now()->addSeconds(1));
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        Carbon::setTestNow('2000-00-00 00:00:59.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        Carbon::setTestNow('2000-00-00 00:01:00.000');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertFalse($job->released);
    }

    public function testItCanLimitPerSecond()
    {
        Container::getInstance()->instance(RateLimiter::class, $limiter = new RateLimiter(new Repository(new ArrayStore)));
        $limiter->for('test', fn () => Limit::perSecond(3));
        $jobFactory = fn () => new class {
            public $released = false;

            public function release()
            {
                $this->released = true;
            }
        };
        $next = fn ($job) => $job;

        $middleware = new RateLimited('test');

        Carbon::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 3; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertFalse($job->released);

            Carbon::setTestNow(now()->addMilliseconds(100));
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        Carbon::setTestNow('2000-00-00 00:00:00.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertNull($result);
        $this->assertTrue($job->released);

        Carbon::setTestNow('2000-00-00 00:00:01.000');

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

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [new RateLimited('test')];
    }
}

class AdminTestJob extends RateLimitedTestJob
{
    public function isAdmin(): bool
    {
        return true;
    }
}

class NonAdminTestJob extends RateLimitedTestJob
{
    public function isAdmin(): bool
    {
        return false;
    }
}

class RateLimitedDontReleaseTestJob extends RateLimitedTestJob
{
    public function middleware(): array
    {
        return [(new RateLimited('test'))->dontRelease()];
    }
}

class RateLimitedReleaseAfterTestJob extends RateLimitedTestJob
{
    public function middleware(): array
    {
        return [(new RateLimited('test'))->releaseAfter(60)];
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

    public function handle(): void
    {
        static::$handled = true;
    }

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

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [new RateLimited(UnitEnumNamedRateLimited::hypervel)];
    }
}
