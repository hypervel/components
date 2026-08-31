<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\CoroutineQueueDeferredCallbacksTest;

use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\run;

class CoroutineQueueDeferredCallbacksTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected function setUp(): void
    {
        parent::setUp();

        CoroutineQueueDeferredCallbacksState::$calls = [];
    }

    public function testImmediateJobDrainsItsDeferredCallbacks(): void
    {
        $queue = $this->configureQueue(new DeferredQueue, 'deferred');

        run(fn () => $queue->push(CoroutineQueueSuccessfulHandler::class));

        $this->assertSame(['normal', 'always'], CoroutineQueueDeferredCallbacksState::$calls);
    }

    public function testFailedJobDrainsOnlyAlwaysDeferredCallbacks(): void
    {
        $failure = null;
        $queue = $this->configureQueue(new DeferredQueue, 'deferred');
        $queue->setExceptionCallback(function (Throwable $exception) use (&$failure): void {
            $failure = $exception;
        });

        run(fn () => $queue->push(CoroutineQueueFailingHandler::class));

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame('Deferred queue job failed.', $failure->getMessage());
        $this->assertSame(['always'], CoroutineQueueDeferredCallbacksState::$calls);
    }

    public function testDelayedJobDrainsCallbacksInItsTimerCoroutine(): void
    {
        $queue = $this->configureQueue(new DeferredQueue, 'deferred');

        run(fn () => $queue->later(0, CoroutineQueueSuccessfulHandler::class));

        $this->assertSame(['normal', 'always'], CoroutineQueueDeferredCallbacksState::$calls);
    }

    public function testBackgroundJobDrainsCallbacksInItsOwnCoroutine(): void
    {
        $queue = $this->configureQueue(new BackgroundQueue, 'background');

        run(fn () => $queue->push(CoroutineQueueSuccessfulHandler::class));

        $this->assertSame(['normal', 'always'], CoroutineQueueDeferredCallbacksState::$calls);
    }

    private function configureQueue(BackgroundQueue|DeferredQueue $queue, string $connectionName): BackgroundQueue|DeferredQueue
    {
        $queue->setConnectionName($connectionName);
        $queue->setContainer($this->app);

        return $queue;
    }
}

class CoroutineQueueSuccessfulHandler
{
    public function fire(SyncJob $job, mixed $data): void
    {
        defer(fn () => CoroutineQueueDeferredCallbacksState::$calls[] = 'normal');
        defer(fn () => CoroutineQueueDeferredCallbacksState::$calls[] = 'always', always: true);
    }
}

class CoroutineQueueFailingHandler extends CoroutineQueueSuccessfulHandler
{
    public function fire(SyncJob $job, mixed $data): void
    {
        parent::fire($job, $data);

        throw new RuntimeException('Deferred queue job failed.');
    }

    public function failed(): void
    {
    }
}

class CoroutineQueueDeferredCallbacksState
{
    /**
     * @var list<string>
     */
    public static array $calls = [];
}
