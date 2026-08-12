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

    protected function assertJobWasReleasedImmediately($class, string $key): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->with(0)->once();
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class($key)),
        ]);

        $this->assertTrue($class::$handled);
    }

    protected function assertJobWasReleasedWithDelay($class, string $key): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('release')->withArgs(function ($delay) {
            return $delay >= 600;
        })->once();
        $job->shouldReceive('isReleased')->andReturn(true);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(true);

        $instance->call($job, [
            'command' => serialize($command = new $class($key)),
        ]);

        $this->assertFalse($class::$handled);
    }

    protected function assertJobRanSuccessfully($class, string $key): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = m::mock(Job::class);

        $job->shouldReceive('hasFailed')->once()->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->once()->andReturn(false);
        $job->shouldReceive('delete')->once();

        $instance->call($job, [
            'command' => serialize($command = new $class($key)),
        ]);

        $this->assertTrue($class::$handled);
    }

    public function testItCanBackoffUsingException(): void
    {
        $job = new class {
            public ?int $releasedAfter = null;

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
            ->shouldReceive('report')
            ->twice()
            ->with(m::type(RuntimeException::class));

        $job = new class {
            public function release()
            {
                return $this;
            }
        };
        $next = function () {
            throw new RuntimeException('Whoops!');
        };

        $middleware = (new ThrottlesExceptions)->store('redis');

        $middleware->report();
        $middleware->handle($job, $next);

        $middleware->report(fn () => true);
        $middleware->handle($job, $next);

        $middleware->report(fn () => false);
        $middleware->handle($job, $next);
    }
}

class CircuitBreakerRedisStoreTestJob
{
    use InteractsWithQueue;
    use Queueable;

    public static bool $handled = false;

    public string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function handle(): void
    {
        static::$handled = true;

        throw new Exception;
    }

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

    public string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function handle(): void
    {
        static::$handled = true;
    }

    public function middleware(): array
    {
        return [(new ThrottlesExceptions(2, 10 * 60))->store('redis')->by($this->key)];
    }
}
