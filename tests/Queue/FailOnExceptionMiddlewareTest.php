<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Middleware;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Queue\Middleware\FailOnException;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class FailOnExceptionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FailOnExceptionMiddlewareTestJob::$_middleware = [];
    }

    #[DataProvider('middlewareDataProvider')]
    public function testMiddleware(string $thrown, FailOnException $middleware, bool $expectedToFail)
    {
        FailOnExceptionMiddlewareTestJob::$_middleware = [$middleware];

        $job = new FailOnExceptionMiddlewareTestJob($thrown);
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $fakeJob = new FakeJob();
        $job->setJob($fakeJob);

        try {
            $instance->call($fakeJob, [
                'command' => serialize($job),
            ]);

            $this->fail('Did not throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf($thrown, $e);
        }

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
    public function testCanTestAgainstJobProperties(mixed $value, bool $expectedToFail)
    {
        FailOnExceptionMiddlewareTestJob::$_middleware = [
            new FailOnException(fn (Throwable $thrown, FailOnExceptionMiddlewareTestJob $job) => $job->value === 'abc'),
        ];

        $job = new FailOnExceptionMiddlewareTestJob(InvalidArgumentException::class, $value);
        $instance = new CallQueuedHandler(new Dispatcher($this->app), $this->app);

        $fakeJob = new FakeJob();
        $job->setJob($fakeJob);

        try {
            $instance->call($fakeJob, [
                'command' => serialize($job),
            ]);

            $this->fail('Did not throw exception');
        } catch (Throwable) {
        }

        $expectedToFail ? $job->assertFailed() : $job->assertNotFailed();
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
        throw new $this->throws();
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return self::$_middleware;
    }
}
