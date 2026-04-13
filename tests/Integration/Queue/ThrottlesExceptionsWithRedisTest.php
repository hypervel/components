<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\ThrottlesExceptionsWithRedisTest;

use Exception;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

#[RequiresPhpExtension('redis')]
/**
 * @internal
 * @coversNothing
 */
class ThrottlesExceptionsWithRedisTest extends TestCase
{
    use InteractsWithRedis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(now());
    }

    public function testCircuitIsOpenedForJobErrors()
    {
        $this->assertJobWasReleasedImmediately(CircuitBreakerWithRedisTestJob::class, $key = Str::random());
        $this->assertJobWasReleasedImmediately(CircuitBreakerWithRedisTestJob::class, $key);
        $this->assertJobWasReleasedWithDelay(CircuitBreakerWithRedisTestJob::class, $key);
    }

    public function testCircuitStaysClosedForSuccessfulJobs()
    {
        $this->assertJobRanSuccessfully(CircuitBreakerWithRedisSuccessfulJob::class, $key = Str::random());
        $this->assertJobRanSuccessfully(CircuitBreakerWithRedisSuccessfulJob::class, $key);
        $this->assertJobRanSuccessfully(CircuitBreakerWithRedisSuccessfulJob::class, $key);
    }

    public function testCircuitResetsAfterSuccess()
    {
        $this->assertJobWasReleasedImmediately(CircuitBreakerWithRedisTestJob::class, $key = Str::random());
        $this->assertJobRanSuccessfully(CircuitBreakerWithRedisSuccessfulJob::class, $key);
        $this->assertJobWasReleasedImmediately(CircuitBreakerWithRedisTestJob::class, $key);
        $this->assertJobWasReleasedImmediately(CircuitBreakerWithRedisTestJob::class, $key);
        $this->assertJobWasReleasedWithDelay(CircuitBreakerWithRedisTestJob::class, $key);
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

    public function testReportingExceptions()
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

        $middleware = new ThrottlesExceptionsWithRedis;

        $middleware->report();
        $middleware->handle($job, $next);

        $middleware->report(fn () => true);
        $middleware->handle($job, $next);

        $middleware->report(fn () => false);
        $middleware->handle($job, $next);
    }
}

class CircuitBreakerWithRedisTestJob
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
        return [(new ThrottlesExceptionsWithRedis(2, 10 * 60))->by($this->key)];
    }
}

class CircuitBreakerWithRedisSuccessfulJob
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
        return [(new ThrottlesExceptionsWithRedis(2, 10 * 60))->by($this->key)];
    }
}
