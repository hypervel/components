<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\ThrottlesExceptionsTest;

use Exception;
use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Queue\Middleware\FailOnException;
use Hypervel\Queue\Middleware\ThrottlesExceptions;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ThrottlesExceptionsTest extends TestCase
{
    public function testCircuitIsOpenedForJobErrors(): void
    {
        $this->assertJobWasReleasedImmediately(CircuitBreakerTestJob::class);
        $this->assertJobWasReleasedImmediately(CircuitBreakerTestJob::class);
        $this->assertJobWasReleasedWithDelay(CircuitBreakerTestJob::class);
    }

    public function testCircuitStaysClosedForSuccessfulJobs(): void
    {
        $this->assertJobRanSuccessfully(CircuitBreakerSuccessfulJob::class);
        $this->assertJobRanSuccessfully(CircuitBreakerSuccessfulJob::class);
        $this->assertJobRanSuccessfully(CircuitBreakerSuccessfulJob::class);
    }

    public function testCircuitResetsAfterSuccess(): void
    {
        $this->assertJobWasReleasedImmediately(CircuitBreakerTestJob::class);
        $this->assertJobRanSuccessfully(CircuitBreakerSuccessfulJob::class);
        $this->assertJobWasReleasedImmediately(CircuitBreakerTestJob::class);
        $this->assertJobWasReleasedImmediately(CircuitBreakerTestJob::class);
        $this->assertJobWasReleasedWithDelay(CircuitBreakerTestJob::class);
    }

    public function testCircuitCanSkipJob(): void
    {
        $this->assertJobWasDeleted(CircuitBreakerSkipJob::class);
    }

    public function testCircuitCanFailJob(): void
    {
        $this->assertJobWasFailed(CircuitBreakerFailedJob::class);
    }

    /**
     * Assert the failing job is released without a delay.
     *
     * @param class-string<CircuitBreakerTestJob> $class
     */
    protected function assertJobWasReleasedImmediately(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($job->isReleased());
        $this->assertSame(0, $job->releaseDelay);

        $this->assertTrue($class::$handled);
    }

    /**
     * Assert the throttled job is released with a delay.
     *
     * @param class-string<CircuitBreakerTestJob> $class
     */
    protected function assertJobWasReleasedWithDelay(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($job->isReleased());
        $this->assertGreaterThanOrEqual(590, $job->releaseDelay);
        $this->assertLessThanOrEqual(610, $job->releaseDelay);

        $this->assertFalse($class::$handled);
    }

    /**
     * Assert the failing job is deleted.
     *
     * @param class-string<CircuitBreakerSkipJob> $class
     */
    protected function assertJobWasDeleted(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($job->isDeleted());

        $this->assertTrue($class::$handled);
    }

    /**
     * Assert the job is marked as failed.
     *
     * @param class-string<CircuitBreakerFailedJob> $class
     */
    protected function assertJobWasFailed(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($job->hasFailed());
        $this->assertFalse($job->isReleased());

        $this->assertTrue($class::$handled);
    }

    /**
     * Assert the successful job runs and is deleted.
     *
     * @param class-string<CircuitBreakerSuccessfulJob> $class
     */
    protected function assertJobRanSuccessfully(string $class): void
    {
        $class::$handled = false;
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $job = new FakeJob;

        $instance->call($job, [
            'command' => serialize($command = new $class),
        ]);

        $this->assertTrue($job->isDeleted());

        $this->assertTrue($class::$handled);
    }

    public function testItCanLimitPerMinute(): void
    {
        $jobFactory = fn (): object => new class {
            public bool $released = false;

            public bool $handled = false;

            /**
             * Release the job.
             */
            public function release(): static
            {
                $this->released = true;

                return $this;
            }
        };
        $next = function (object $job): never {
            $job->handled = true;

            throw new RuntimeException('Whoops!');
        };

        $middleware = new ThrottlesExceptions(3, 60);

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 3; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertTrue($job->released);
            $this->assertTrue($job->handled);

            CarbonImmutable::setTestNow(now()->addSecond());
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertFalse($job->handled);

        CarbonImmutable::setTestNow('2000-00-00 00:00:59.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertFalse($job->handled);

        CarbonImmutable::setTestNow('2000-00-00 00:01:00.000');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertTrue($job->handled);
    }

    public function testItCanLimitPerSecond(): void
    {
        $jobFactory = fn (): object => new class {
            public bool $released = false;

            public bool $handled = false;

            /**
             * Release the job.
             */
            public function release(): static
            {
                $this->released = true;

                return $this;
            }
        };
        $next = function (object $job): never {
            $job->handled = true;

            throw new RuntimeException('Whoops!');
        };

        $middleware = new ThrottlesExceptions(3, 1);

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 3; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertTrue($job->released);
            $this->assertTrue($job->handled);

            CarbonImmutable::setTestNow(now()->addMilliseconds(100));
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertFalse($job->handled);

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertFalse($job->handled);

        CarbonImmutable::setTestNow('2000-00-00 00:00:01.000');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertTrue($job->handled);
    }

    public function testLimitingWithDefaultValues(): void
    {
        $jobFactory = fn (): object => new class {
            public bool $released = false;

            public bool $handled = false;

            /**
             * Release the job.
             */
            public function release(): static
            {
                $this->released = true;

                return $this;
            }
        };
        $next = function (object $job): never {
            $job->handled = true;

            throw new RuntimeException('Whoops!');
        };

        $middleware = new ThrottlesExceptions;

        CarbonImmutable::setTestNow('2000-00-00 00:00:00.000');

        for ($i = 0; $i < 10; ++$i) {
            $result = $middleware->handle($job = $jobFactory(), $next);
            $this->assertSame($job, $result);
            $this->assertTrue($job->released);
            $this->assertTrue($job->handled);

            CarbonImmutable::setTestNow(now()->addSecond());
        }

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertFalse($job->handled);

        CarbonImmutable::setTestNow('2000-00-00 00:09:59.999');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertFalse($job->handled);

        CarbonImmutable::setTestNow('2000-00-00 00:10:00.000');

        $result = $middleware->handle($job = $jobFactory(), $next);
        $this->assertSame($job, $result);
        $this->assertTrue($job->released);
        $this->assertTrue($job->handled);
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

        $middleware = (new ThrottlesExceptions)->backoff(
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

    public function testDeniedFailureConsumeUsesTheCircuitOpenDelayInsteadOfBackoff(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
        $key = 'concurrent-last-slot';
        $limiter = $this->app->make(RateLimiter::class)->store();
        $policy = Limit::perMinute(1)->by('hypervel:queue:throttles-exceptions:' . $key);
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
        $middleware = (new ThrottlesExceptions(1, 60))
            ->by($key)
            ->backoff(5);

        $result = $middleware->handle($job, function () use ($limiter, $policy): never {
            $this->assertTrue($limiter->consume($policy)->allowed());

            throw new RuntimeException('Whoops!');
        });

        $this->assertSame($job, $result);
        $this->assertSame(63, $job->releasedAfter);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCancellationBypassesFailurePolicyAndRateLimitAccounting(bool $cleanupThrows): void
    {
        $limiter = m::mock(Limiter::class);
        $limiter->expects('inspect')
            ->with(m::type(Limit::class))
            ->andReturn(new LimitResult(true, 10, 10, 0, 0));
        $limiter->shouldNotReceive('clear', 'consume');

        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->expects('store')->with(null)->andReturn($limiter);
        $this->app->instance(RateLimiter::class, $rateLimiter);

        $callbacksCalled = false;
        $middleware = (new ThrottlesExceptions)
            ->by('cancellation')
            ->when(static function () use (&$callbacksCalled): bool {
                $callbacksCalled = true;

                return true;
            })
            ->report(static function () use (&$callbacksCalled): bool {
                $callbacksCalled = true;

                return true;
            })
            ->deleteWhen(static function () use (&$callbacksCalled): bool {
                $callbacksCalled = true;

                return false;
            })
            ->failWhen(static function () use (&$callbacksCalled): bool {
                $callbacksCalled = true;

                return false;
            });
        $job = m::mock();
        $job->shouldNotReceive('release', 'delete', 'fail');
        $gate = $this->armCurrentCoroutineCancellation();
        $cleanupException = new RuntimeException('Cleanup failed.');

        try {
            $middleware->handle($job, static function () use ($gate, $cleanupThrows, $cleanupException): never {
                try {
                    $gate->push(true);

                    throw new RuntimeException('Cancellation was not delivered.');
                } finally {
                    if ($cleanupThrows) {
                        throw $cleanupException;
                    }
                }
            });
            $this->fail('Expected cancellation to escape the middleware.');
        } catch (Throwable $exception) {
            if ($cleanupThrows) {
                $this->assertSame($cleanupException, $exception);
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception);
            }

            $this->assertFalse($callbacksCalled);
        }
    }

    public function testIndependentChildCancellationIsCountedAndReleased(): void
    {
        $job = (new CircuitBreakerTestJob)->withFakeQueueInteractions();
        $failure = new ChildCancellationException(previous: new CanceledException);
        $limiter = m::mock(Limiter::class);
        $limiter->expects('inspect')->with(m::type(Limit::class))->andReturn(new LimitResult(true, 10, 10, 0, 0));
        $limiter->expects('consume')->with(m::type(Limit::class))->andReturn(new LimitResult(true, 10, 9, 0, 0));
        $limiter->shouldNotReceive('clear');
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->expects('store')->with(null)->andReturn($limiter);
        $this->app->instance(RateLimiter::class, $rateLimiter);
        $this->spy(ExceptionHandler::class)->expects('report')->with($failure, []);

        (new ThrottlesExceptions)->backoff(1)->report()->handle($job, static fn () => throw $failure);

        $job->assertReleased(60)->assertNotFailed();
    }

    #[TestWith(['release'])]
    #[TestWith(['delete'])]
    #[TestWith(['fail'])]
    public function testInnerTerminalActionIsNotReplacedByAnotherRelease(string $action): void
    {
        $job = (new CircuitBreakerTestJob)->withFakeQueueInteractions();
        $exception = new RuntimeException('Inner pipeline failed.');
        $released = 0;
        $failed = 0;
        $job->job->beforeReleasing(static function () use (&$released): void { ++$released; });
        $job->job->beforeFailing(static function () use (&$failed): void { ++$failed; });

        try {
            (new ThrottlesExceptions)->handle($job, static function () use ($job, $action, $exception): never {
                if ($action === 'fail') {
                    (new FailOnException([RuntimeException::class]))->handle($job, static function () use ($exception): never {
                        throw $exception;
                    });
                } else {
                    $job->{$action}();
                }

                throw $exception;
            });
            $this->fail('Expected the original exception.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame($action === 'release' ? 1 : 0, $released);
        $this->assertSame($action === 'fail' ? 1 : 0, $failed);
        $this->assertSame($action === 'delete', $job->job->isDeleted());
    }

    public function testReportingExceptions(): void
    {
        $this->spy(ExceptionHandler::class)
            ->expects('report')
            ->times(2)
            ->with(m::type(RuntimeException::class), []);

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

        $middleware = new ThrottlesExceptions;

        $middleware->report();
        $middleware->handle($job, $next);

        $middleware->report(fn (): bool => true);
        $middleware->handle($job, $next);

        $middleware->report(fn (): bool => false);
        $middleware->handle($job, $next);
    }

    public function testCallbacksReceiveTheSelectedPackageLimiter(): void
    {
        config([
            'rate-limiter.stores.queue' => [
                'driver' => 'worker-array',
            ],
        ]);

        $expected = $this->app->make(RateLimiter::class)->store('queue');
        $whenLimiter = null;
        $reportLimiter = null;
        $job = new class {
            /**
             * Release the job.
             */
            public function release(): static
            {
                return $this;
            }
        };

        $middleware = (new ThrottlesExceptions)
            ->store('queue')
            ->when(function (RuntimeException $throwable, Limiter $limiter) use (&$whenLimiter): bool {
                $whenLimiter = $limiter;

                return true;
            })
            ->report(function (RuntimeException $throwable, Limiter $limiter) use (&$reportLimiter): bool {
                $reportLimiter = $limiter;

                return false;
            });

        $this->assertSame($job, $middleware->handle($job, function (): never {
            throw new RuntimeException('Whoops!');
        }));
        $this->assertSame($expected, $whenLimiter);
        $this->assertSame($expected, $reportLimiter);
    }

    public function testUsesRawJobClassNameForRateLimiterKey(): void
    {
        $job = new class {
        };

        $this->assertSame(
            'hypervel:queue:throttles-exceptions:' . get_class($job),
            (new ExposesThrottlesExceptions)->getKeyForTest($job),
        );
    }

    public function testUsesRawDisplayNameForRateLimiterKeyWhenAvailable(): void
    {
        $job = new class {
            /**
             * Get the job display name.
             */
            public function displayName(): string
            {
                return 'App\Actions\ThrottlesExceptionsTestAction';
            }
        };

        $this->assertSame(
            'hypervel:queue:throttles-exceptions:App\Actions\ThrottlesExceptionsTestAction',
            (new ExposesThrottlesExceptions)->getKeyForTest($job),
        );
    }

    /**
     * Arm exact cancellation of the current coroutine at a controlled channel handoff.
     */
    private function armCurrentCoroutineCancellation(): Channel
    {
        $gate = new Channel(1);
        $coroutineId = EngineCoroutine::id();

        EngineCoroutine::create(static function () use ($coroutineId, $gate): void {
            $gate->pop();
            EngineCoroutine::cancelById($coroutineId, throwException: true);
        });

        return $gate;
    }
}

class CircuitBreakerTestJob
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

        throw new Exception;
    }

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(2, 10 * 60))->by('test')];
    }
}

class CircuitBreakerSkipJob
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

        throw new Exception;
    }

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(2, 10 * 60))->deleteWhen(Exception::class)];
    }
}

class CircuitBreakerFailedJob
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

        throw new Exception;
    }

    /**
     * Get the job middleware.
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(2, 10 * 60))->failWhen(Exception::class)];
    }
}

class CircuitBreakerSuccessfulJob
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
        return [(new ThrottlesExceptions(2, 10 * 60))->by('test')];
    }
}

class ExposesThrottlesExceptions extends ThrottlesExceptions
{
    /**
     * Get the rate limiter key for the job.
     */
    public function getKeyForTest(mixed $job): string
    {
        return $this->getKey($job);
    }
}
