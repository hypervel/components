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
use Hypervel\RateLimiter\Unlimited;
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

        $rateLimiter->for($testJob->key, function (RedisRateLimitedTestJob $job): Unlimited {
            return Limit::none();
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobRanSuccessfully($testJob);
    }

    public function testUnlimitedJobsAreExecutedUsingIntBackedEnum(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $rateLimiter->for(RedisBackedEnumNamedRateLimited::Zero, function (RedisRateLimitedTestJob $job): Unlimited {
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

        $rateLimiter->for($testJob->key, function (RedisRateLimitedTestJob $job): Limit {
            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasReleased($testJob);
    }

    public function testExplicitZeroReleaseDelayIsRespected(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $testJob = new RedisRateLimitedZeroReleaseAfterTestJob;

        $rateLimiter->for($testJob->key, function (RedisRateLimitedTestJob $job): Limit {
            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasReleasedAfter($testJob, 0);
    }

    public function testRateLimitedJobsCanBeSkippedOnLimitReached(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $testJob = new RedisRateLimitedDontReleaseTestJob;

        $rateLimiter->for($testJob->key, function (RedisRateLimitedTestJob $job): Limit {
            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasSkipped($testJob);
    }

    public function testLimitsAreNotHitWhenAnotherLimitIsReached(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);
        $testJob = new RedisRateLimitedTestJob;

        $rateLimiter->for($testJob->key, function (): array {
            return [
                Limit::perHour(10)->by('global'),
                Limit::perHour(1)->by('tenant'),
            ];
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasReleased($testJob);
        $this->assertJobWasReleased($testJob);

        $limiter = $rateLimiter->store('redis');
        $this->assertSame(9, $limiter->inspect(Limit::perHour(10)->by('global'), $testJob->key)->remaining());
        $this->assertSame(0, $limiter->inspect(Limit::perHour(1)->by('tenant'), $testJob->key)->remaining());
    }

    public function testLimitsAreNotHitWhenAnotherLimitIsReachedAndJobIsSkipped(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);
        $testJob = new RedisRateLimitedDontReleaseTestJob;

        $rateLimiter->for($testJob->key, function (): array {
            return [
                Limit::perHour(10)->by('global'),
                Limit::perHour(1)->by('tenant'),
            ];
        });

        $this->assertJobRanSuccessfully($testJob);
        $this->assertJobWasSkipped($testJob);
        $this->assertJobWasSkipped($testJob);

        $limiter = $rateLimiter->store('redis');
        $this->assertSame(9, $limiter->inspect(Limit::perHour(10)->by('global'), $testJob->key)->remaining());
        $this->assertSame(0, $limiter->inspect(Limit::perHour(1)->by('tenant'), $testJob->key)->remaining());
    }

    public function testJobsCanHaveConditionalRateLimits(): void
    {
        $rateLimiter = $this->app->make(RateLimiter::class);

        $adminJob = new RedisAdminTestJob;

        $rateLimiter->for($adminJob->key, function (RedisAdminTestJob $job): Limit|Unlimited {
            if ($job->isAdmin()) {
                return Limit::none();
            }

            return Limit::perMinute(1);
        });

        $this->assertJobRanSuccessfully($adminJob);
        $this->assertJobRanSuccessfully($adminJob);

        $nonAdminJob = new RedisNonAdminTestJob;

        $rateLimiter->for($nonAdminJob->key, function (RedisNonAdminTestJob $job): Limit|Unlimited {
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

        $fetch = (function (string $name): mixed {
            return $this->{$name};
        })->bindTo($restoredRateLimited, RateLimited::class);

        $this->assertFalse($restoredRateLimited->shouldRelease);
        $this->assertSame('limiterName', $fetch('limiterName'));
        $this->assertSame('redis', $fetch('storeName'));
        $this->assertInstanceOf(RateLimiter::class, $fetch('limiter'));
    }

    /**
     * Assert the job runs and is deleted.
     */
    protected function assertJobRanSuccessfully(RedisRateLimitedTestJob $testJob): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertTrue($testJob::$handled);
    }

    /**
     * Assert the job is released without running.
     */
    protected function assertJobWasReleased(RedisRateLimitedTestJob $testJob): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('release');
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertFalse($testJob::$handled);
    }

    /**
     * Assert the job is released with the given delay.
     */
    protected function assertJobWasReleasedAfter(RedisRateLimitedTestJob $testJob, int $delay): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('release')->with($delay);
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($testJob),
        ]);

        $this->assertFalse($testJob::$handled);
    }

    /**
     * Assert the job is deleted without running.
     */
    protected function assertJobWasSkipped(RedisRateLimitedTestJob $testJob): void
    {
        $testJob::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

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

    /**
     * Create a job with a unique limiter name.
     */
    public function __construct()
    {
        $this->key = Str::random(10);
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
        return [(new RateLimited($this->key))->store('redis')];
    }
}

class RedisAdminTestJob extends RedisRateLimitedTestJob
{
    /**
     * Determine whether the job runs as an administrator.
     */
    public function isAdmin(): bool
    {
        return true;
    }
}

class RedisNonAdminTestJob extends RedisRateLimitedTestJob
{
    /**
     * Determine whether the job runs as an administrator.
     */
    public function isAdmin(): bool
    {
        return false;
    }
}

class RedisRateLimitedDontReleaseTestJob extends RedisRateLimitedTestJob
{
    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new RateLimited($this->key))->store('redis')->dontRelease()];
    }
}

class RedisRateLimitedZeroReleaseAfterTestJob extends RedisRateLimitedTestJob
{
    /**
     * Get the job middleware.
     */
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
    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new RateLimited(RedisBackedEnumNamedRateLimited::Zero))->store('redis')];
    }
}
