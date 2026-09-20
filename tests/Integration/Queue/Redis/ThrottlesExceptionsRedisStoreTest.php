<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Redis\ThrottlesExceptionsRedisStoreTest;

use Exception;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\ThrottlesExceptions;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

#[RequiresPhpExtension('redis')]
// REMOVED: ThrottlesExceptionsWithRedis is replaced by ThrottlesExceptions::store('redis').
class ThrottlesExceptionsRedisStoreTest extends TestCase
{
    use InteractsWithRedis;

    public function testCircuitIsOpenedForJobErrors(): void
    {
        $this->assertJobWasReleasedImmediately(CircuitBreakerRedisStoreTestJob::class, $key = Str::random());
        $this->assertJobWasReleasedImmediately(CircuitBreakerRedisStoreTestJob::class, $key);
        $this->assertJobWasReleasedWithDelay(CircuitBreakerRedisStoreTestJob::class, $key);
    }

    public function testCircuitStaysClosedForSuccessfulJobs(): void
    {
        $this->assertJobRanSuccessfully(CircuitBreakerRedisStoreSuccessfulJob::class, $key = Str::random());
        $this->assertJobRanSuccessfully(CircuitBreakerRedisStoreSuccessfulJob::class, $key);
        $this->assertJobRanSuccessfully(CircuitBreakerRedisStoreSuccessfulJob::class, $key);
    }

    public function testCircuitResetsAfterSuccess(): void
    {
        $this->assertJobWasReleasedImmediately(CircuitBreakerRedisStoreTestJob::class, $key = Str::random());
        $this->assertJobRanSuccessfully(CircuitBreakerRedisStoreSuccessfulJob::class, $key);
        $this->assertJobWasReleasedImmediately(CircuitBreakerRedisStoreTestJob::class, $key);
        $this->assertJobWasReleasedImmediately(CircuitBreakerRedisStoreTestJob::class, $key);
        $this->assertJobWasReleasedWithDelay(CircuitBreakerRedisStoreTestJob::class, $key);
    }

    /**
     * Assert the failing job is released without a delay.
     *
     * @param class-string<CircuitBreakerRedisStoreTestJob> $class
     */
    protected function assertJobWasReleasedImmediately(string $class, string $key): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('release')->with(0);
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class($key)),
        ]);

        $this->assertTrue($class::$handled);
    }

    /**
     * Assert the throttled job is released with a delay.
     *
     * @param class-string<CircuitBreakerRedisStoreTestJob> $class
     */
    protected function assertJobWasReleasedWithDelay(string $class, string $key): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('release')->withArgs(function (int $delay): bool {
            // The delay is the remainder of the decay window, less wall clock
            // seconds elapsed since the first exception opened the circuit.
            return $delay >= 590 && $delay <= 610;
        });
        $job->expects('isReleased')->times(2)->andReturn(true);
        $job->expects('isDeletedOrReleased')->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class($key)),
        ]);

        $this->assertFalse($class::$handled);
    }

    /**
     * Assert the successful job runs and is deleted.
     *
     * @param class-string<CircuitBreakerRedisStoreSuccessfulJob> $class
     */
    protected function assertJobRanSuccessfully(string $class, string $key): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->expects('hasFailed')->andReturn(false);
        $job->expects('isReleased')->times(2)->andReturn(false);
        $job->expects('isDeletedOrReleased')->andReturn(false);
        $job->expects('delete');

        $instance->call($job, [
            'command' => serialize($command = new $class($key)),
        ]);

        $this->assertTrue($class::$handled);
    }

    public function testItCanBackoffUsingException(): void
    {
        $job = new class {
            public ?int $releasedAfter = null;

            /**
             * Release the job with a delay.
             */
            public function release(int $delay): static
            {
                $this->releasedAfter = $delay;

                return $this;
            }
        };
        $expectedException = new RuntimeException('Whoops!');
        $receivedException = null;
        $next = function () use ($expectedException): never {
            throw $expectedException;
        };

        $middleware = (new ThrottlesExceptions)->store('redis')->backoff(
            function (RuntimeException $throwable) use (&$receivedException): int {
                $receivedException = $throwable;

                return 5;
            },
        );

        $result = $middleware->handle($job, $next);

        $this->assertSame($job, $result);
        $this->assertSame($expectedException, $receivedException);
        $this->assertSame(300, $job->releasedAfter);
    }

    public function testReportingExceptions(): void
    {
        $this->spy(ExceptionHandler::class)
            ->expects('report')
            ->times(2)
            ->with(m::type(RuntimeException::class));

        $job = new class {
            /**
             * Release the job.
             */
            public function release(): static
            {
                return $this;
            }
        };
        $next = function (): never {
            throw new RuntimeException('Whoops!');
        };

        $middleware = (new ThrottlesExceptions)->store('redis');

        $middleware->report();
        $middleware->handle($job, $next);

        $middleware->report(fn (): bool => true);
        $middleware->handle($job, $next);

        $middleware->report(fn (): bool => false);
        $middleware->handle($job, $next);
    }
}

class CircuitBreakerRedisStoreTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    /**
     * Create a job with a rate limiter key.
     */
    public function __construct(public string $key)
    {
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$handled = true;

        throw new Exception;
    }

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(2, 10 * 60))->store('redis')->by($this->key)];
    }
}

class CircuitBreakerRedisStoreSuccessfulJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    /**
     * Create a job with a rate limiter key.
     */
    public function __construct(public string $key)
    {
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
        return [(new ThrottlesExceptions(2, 10 * 60))->store('redis')->by($this->key)];
    }
}
