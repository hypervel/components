<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Redis\RateLimitedRedisStoreTest;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('redis')]
// REMOVED: RateLimitedWithRedis is replaced by RateLimited::store('redis').
class RateLimitedRedisStoreTest extends TestCase
{
    use InteractsWithRedis;

    public function testUnlimitedJobsAreExecuted(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $testJob = new RedisRateLimitedTestJob;

        $rateLimiter->for($testJob->key, function ($job) {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobRanSuccessfully($testJob);
    }

    public function testUnlimitedJobsAreExecutedUsingIntBackedEnum(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for(RedisBackedEnumNamedRateLimited::Zero, function ($job) {
            return Limit::none();
        });

        $testJob = new RedisRateLimitedTestJobUsingBackedEnum;

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobRanSuccessfully($testJob);
    }

    public function testRateLimitedJobsAreNotExecutedOnLimitReached(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $testJob = new RedisRateLimitedTestJob;

        $rateLimiter->for($testJob->key, function ($job) {
            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasReleased($testJob);
    }

    public function testExplicitZeroReleaseDelayIsRespected(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $testJob = new RedisRateLimitedZeroReleaseAfterTestJob;

        $rateLimiter->for($testJob->key, function ($job) {
            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasReleasedAfter($testJob, 0);
    }

    public function testRateLimitedJobsCanBeSkippedOnLimitReached(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $testJob = new RedisRateLimitedDontReleaseTestJob;

        $rateLimiter->for($testJob->key, function ($job) {
            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasSkipped($testJob);
    }

    public function testJobsCanHaveConditionalRateLimits(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $adminJob = new RedisAdminTestJob;

        $rateLimiter->for($adminJob->key, function ($job) {
            if ($job->isAdmin()) {
                return Limit::none();
            }

            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($adminJob);
        $this->assertJobRanSuccessfully($adminJob);

        $nonAdminJob = new RedisNonAdminTestJob;

        $rateLimiter->for($nonAdminJob->key, function ($job) {
            if ($job->isAdmin()) {
                return Limit::none();
            }

            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($nonAdminJob);
        $this->assertJobWasReleased($nonAdminJob);
    }

    public function testMiddlewareSerialization(): void
    {
        $rateLimited = (new RateLimited('limiterName'))->store('redis');
        $rateLimited->shouldRelease = false;

        $restoredRateLimited = unserialize(serialize($rateLimited));

        $fetch = (function (string $name) {
            return $this->{$name};
        })->bindTo($restoredRateLimited, RateLimited::class);

        $this->assertFalse($restoredRateLimited->shouldRelease);
        $this->assertSame('limiterName', $fetch('limiterName'));
        $this->assertSame('redis', $fetch('storeName'));
        $this->assertInstanceOf(RateLimiter::class, $fetch('limiter'));
    }

    protected function assertJobRanSuccessfully(RedisRateLimitedTestJob $testJob): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertTrue($testJob::$handled);
    }

    protected function assertJobWasReleased(RedisRateLimitedTestJob $testJob): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->once();
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertFalse($testJob::$handled);
    }

    protected function assertJobWasReleasedAfter(RedisRateLimitedTestJob $testJob, int $delay): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->once()->with($delay);
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertFalse($testJob::$handled);
    }

    protected function assertJobWasSkipped(RedisRateLimitedTestJob $testJob): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertFalse($testJob::$handled);
    }
}

class RedisRateLimitedTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public string $key;

    public static bool $handled = false;

    public function __construct()
    {
        $this->key = Str::random(10);
    }

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [(new RateLimited($this->key))->store('redis')];
    }
}

class RedisAdminTestJob extends RedisRateLimitedTestJob
{
    public function isAdmin(): bool
    {
        return true;
    }
}

class RedisNonAdminTestJob extends RedisRateLimitedTestJob
{
    public function isAdmin(): bool
    {
        return false;
    }
}

class RedisRateLimitedDontReleaseTestJob extends RedisRateLimitedTestJob
{
    public function middleware(): array
    {
        return [(new RateLimited($this->key))->store('redis')->dontRelease()];
    }
}

class RedisRateLimitedZeroReleaseAfterTestJob extends RedisRateLimitedTestJob
{
    public function middleware(): array
    {
        return [(new RateLimited($this->key))->store('redis')->releaseAfter(0)];
    }
}

enum RedisBackedEnumNamedRateLimited: int
{
    case Zero = 0;
}

class RedisRateLimitedTestJobUsingBackedEnum extends RedisRateLimitedTestJob
{
    public function middleware(): array
    {
        return [(new RateLimited(RedisBackedEnumNamedRateLimited::Zero))->store('redis')];
    }
}
