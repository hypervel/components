<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Bootstrap\TaskCallback;
use Hypervel\Core\Events\OnTask;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Constant;
use Swoole\Server;

class TaskCallbackNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testEachTaskGetsAnEmptyContextUntilItHasTerminated(): void
    {
        CoroutineContext::set('worker', 'outer');
        $seen = [];
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->with(m::type(OnTask::class))
            ->andReturnUsing(function (OnTask $event) use (&$seen): void {
                $seen[] = [CoroutineContext::get('worker'), CoroutineContext::get('task')];
                CoroutineContext::set('task', $event->task->data);
            });
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->with(m::type(TaskTerminated::class))
            ->andReturnUsing(function () use (&$seen): void {
                $seen[] = CoroutineContext::get('task');
            });

        $callback = $this->makeCallback($dispatcher);
        $callback->onTask(m::mock(Server::class), 1, 0, 'first');
        $callback->onTask(m::mock(Server::class), 2, 0, 'second');

        $this->assertSame([[null, null], 'first', [null, null], 'second'], $seen);
        $this->assertSame('outer', CoroutineContext::get('worker'));
        $this->assertFalse(CoroutineContext::has('task'));
    }

    public function testTheWorkerContextIsRestoredWhenATaskFails(): void
    {
        CoroutineContext::set('worker', 'outer');
        $exception = new RuntimeException('Task failed.');
        $terminatedWith = null;
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andReturnUsing(function () use ($exception): void {
                CoroutineContext::set('task', 'failed');

                throw $exception;
            });
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TaskTerminated::class))
            ->andReturnUsing(function () use (&$terminatedWith): void {
                $terminatedWith = CoroutineContext::get('task');
            });

        try {
            $this->makeCallback($dispatcher)->onTask(m::mock(Server::class), 1, 0, 'payload');
            $this->fail('Expected the task failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertSame('failed', $terminatedWith);
        $this->assertSame('outer', CoroutineContext::get('worker'));
        $this->assertFalse(CoroutineContext::has('task'));
    }

    /**
     * Create a task callback for tasks that run outside a coroutine.
     */
    private function makeCallback(Dispatcher $dispatcher): TaskCallback
    {
        return new TaskCallback($dispatcher, new Repository([
            'server' => ['settings' => [Constant::OPTION_TASK_ENABLE_COROUTINE => false]],
        ]));
    }
}
