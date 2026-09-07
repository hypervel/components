<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Middleware;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Queue\Middleware\FailOnException;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Swoole\Coroutine\CanceledException;
use Throwable;

class FailOnExceptionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FailOnExceptionMiddlewareTestJob::$_middleware = [];
    }

    #[DataProvider('middlewareDataProvider')]
    public function testMiddleware(string $thrown, FailOnException $middleware, bool $expectedToFail): void
    {
        FailOnExceptionMiddlewareTestJob::$_middleware = [$middleware];

        $job = new FailOnExceptionMiddlewareTestJob($thrown);
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $fakeJob = new FakeJob;
        $job->setJob($fakeJob);

        $caughtException = null;

        try {
            $instance->call($fakeJob, [
                'command' => serialize($job),
            ]);
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf($thrown, $caughtException);

        $expectedToFail ? $job->assertFailed() : $job->assertNotFailed();
    }

    /**
     * @return array<string, array{class-string<Throwable>, FailOnException, bool}>
     */
    public static function middlewareDataProvider(): array
    {
        return [
            'exception is in list' => [
                InvalidArgumentException::class,
                new FailOnException([InvalidArgumentException::class]),
                true,
            ],
            'exception is not in list' => [
                LogicException::class,
                new FailOnException([InvalidArgumentException::class]),
                false,
            ],
        ];
    }

    #[TestWith(['abc', true])]
    #[TestWith(['tots', false])]
    public function testCanTestAgainstJobProperties(mixed $value, bool $expectedToFail): void
    {
        FailOnExceptionMiddlewareTestJob::$_middleware = [
            new FailOnException(fn (Throwable $thrown, FailOnExceptionMiddlewareTestJob $job) => $job->value === 'abc'),
        ];

        $job = new FailOnExceptionMiddlewareTestJob(InvalidArgumentException::class, $value);
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $fakeJob = new FakeJob;
        $job->setJob($fakeJob);

        $caughtException = null;

        try {
            $instance->call($fakeJob, [
                'command' => serialize($job),
            ]);
        } catch (InvalidArgumentException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $caughtException, 'Did not throw expected exception');

        $expectedToFail ? $job->assertFailed() : $job->assertNotFailed();
    }

    public function testCancellationBypassesTheFailurePredicateAndJobFailure(): void
    {
        $predicateCalled = false;
        $middleware = new FailOnException(
            static function () use (&$predicateCalled): bool {
                $predicateCalled = true;

                return true;
            },
        );
        $job = m::mock();
        $job->shouldNotReceive('fail');
        $gate = $this->armCurrentCoroutineCancellation();

        try {
            $middleware->handle($job, static function () use ($gate): never {
                $gate->push(true);

                throw new LogicException('Cancellation was not delivered.');
            });
            $this->fail('Expected cancellation to escape the middleware.');
        } catch (CanceledException) {
            $this->assertFalse($predicateCalled);
        }
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

class FailOnExceptionMiddlewareTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static array $_middleware = [];

    public int $tries = 2;

    /**
     * Create a new job instance.
     *
     * @param class-string<Throwable> $throws
     */
    public function __construct(
        private string $throws,
        public mixed $value = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        throw new $this->throws;
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return self::$_middleware;
    }
}
