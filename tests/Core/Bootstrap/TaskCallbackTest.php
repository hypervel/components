<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Bootstrap\TaskCallback;
use Hypervel\Core\Events\OnTask;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Constant;
use Swoole\Server;
use Swoole\Server\Task;

class TaskCallbackTest extends TestCase
{
    public function testLegacySignatureBuildsTaskEvent(): void
    {
        $server = m::mock(Server::class);
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (OnTask $event) use ($server): bool {
                $this->assertSame($server, $event->server);
                $this->assertSame(12, $event->task->id);
                $this->assertSame(3, $event->task->worker_id);
                $this->assertSame(['payload' => true], $event->task->data);

                return true;
            }));
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnFalse();

        $this->makeCallback($dispatcher)->onTask($server, 12, 3, ['payload' => true]);
    }

    #[DataProvider('objectModeSettings')]
    public function testDedicatedTaskSettingsUseNativeObjectSignature(array $settings): void
    {
        $server = m::mock(Server::class);
        $task = new Task;
        $task->id = 21;
        $task->worker_id = 4;
        $task->data = 'payload';

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (OnTask $event) use ($server, $task): bool {
                $this->assertSame($server, $event->server);
                $this->assertSame($task, $event->task);

                return true;
            }));
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (TaskTerminated $event) use ($server, $task): bool {
                $this->assertSame($server, $event->server);
                $this->assertSame($task, $event->task);

                return true;
            }));

        $this->makeCallback($dispatcher, $settings)->onTask($server, $task);
    }

    public static function objectModeSettings(): array
    {
        return [
            'coroutine tasks' => [[Constant::OPTION_TASK_ENABLE_COROUTINE => true]],
            'task object' => [[Constant::OPTION_TASK_OBJECT => true]],
            'legacy task object alias' => [[Constant::OPTION_TASK_USE_OBJECT => true]],
        ];
    }

    public function testLegacyAliasPresenceTakesPrecedenceOverTaskObject(): void
    {
        $server = m::mock(Server::class);
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (OnTask $event): bool {
                $this->assertSame(31, $event->task->id);
                $this->assertSame('legacy', $event->task->data);

                return true;
            }));
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnFalse();

        $this->makeCallback($dispatcher, [
            Constant::OPTION_TASK_USE_OBJECT => false,
            Constant::OPTION_TASK_OBJECT => true,
        ])->onTask($server, 31, 5, 'legacy');
    }

    public function testLegacyResultIsFinishedThroughServer(): void
    {
        $server = m::mock(Server::class);
        $server->shouldReceive('finish')->once()->with('completed');

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andReturnUsing(function (OnTask $event): void {
                $event->setResult('completed');
            });
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnFalse();

        $this->makeCallback($dispatcher)->onTask($server, 41, 6, 'payload');
    }

    public function testLegacyResultIsFinishedBeforeTerminalDispatch(): void
    {
        $order = [];
        $server = m::mock(Server::class);
        $server->shouldReceive('finish')
            ->once()
            ->with('completed')
            ->andReturnUsing(function () use (&$order): bool {
                $order[] = 'finish';

                return true;
            });

        $task = null;
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andReturnUsing(function (OnTask $event) use (&$order, &$task): void {
                $task = $event->task;
                $event->setResult('completed');
                $order[] = 'task';
            });
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (TaskTerminated $event) use ($server, &$order, &$task): bool {
                $this->assertSame($server, $event->server);
                $this->assertSame($task, $event->task);
                $order[] = 'terminated';

                return true;
            }));

        $this->makeCallback($dispatcher)->onTask($server, 42, 7, 'payload');

        $this->assertSame(['task', 'finish', 'terminated'], $order);
    }

    public function testTaskFailureSkipsFinishButStillDispatchesTerminalEvent(): void
    {
        $exception = new RuntimeException('Task failed.');
        $server = m::mock(Server::class);
        $server->shouldNotReceive('finish');

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andThrow($exception);
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TaskTerminated::class));

        try {
            $this->makeCallback($dispatcher)->onTask($server, 43, 8, 'payload');
            $this->fail('Expected the task failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testFinishFailureStillDispatchesTerminalEvent(): void
    {
        $exception = new RuntimeException('Finish failed.');
        $server = m::mock(Server::class);
        $server->shouldReceive('finish')->once()->andThrow($exception);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andReturnUsing(function (OnTask $event): void {
                $event->setResult('completed');
            });
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TaskTerminated::class));

        try {
            $this->makeCallback($dispatcher)->onTask($server, 44, 9, 'payload');
            $this->fail('Expected the finish failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testTerminalFailurePropagatesAfterSuccessfulTask(): void
    {
        $exception = new RuntimeException('Terminal dispatch failed.');
        $server = m::mock(Server::class);
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class));
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TaskTerminated::class))
            ->andThrow($exception);

        try {
            $this->makeCallback($dispatcher)->onTask($server, 45, 10, 'payload');
            $this->fail('Expected the terminal failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testTaskFailureRemainsPrimaryWhenTerminalDispatchAlsoFails(): void
    {
        $taskException = new RuntimeException('Task failed.');
        $terminalException = new RuntimeException('Terminal dispatch failed.');
        $server = m::mock(Server::class);
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andThrow($taskException);
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TaskTerminated::class))
            ->andThrow($terminalException);

        try {
            $this->makeCallback($dispatcher)->onTask($server, 46, 11, 'payload');
            $this->fail('Expected the task failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($taskException, $throwable);
        }
    }

    public function testFinishFailureRemainsPrimaryWhenTerminalDispatchAlsoFails(): void
    {
        $finishException = new RuntimeException('Finish failed.');
        $terminalException = new RuntimeException('Terminal dispatch failed.');
        $server = m::mock(Server::class);
        $server->shouldReceive('finish')->once()->andThrow($finishException);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(OnTask::class))
            ->andReturnUsing(function (OnTask $event): void {
                $event->setResult('completed');
            });
        $dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(TaskTerminated::class)
            ->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(TaskTerminated::class))
            ->andThrow($terminalException);

        try {
            $this->makeCallback($dispatcher)->onTask($server, 47, 12, 'payload');
            $this->fail('Expected the finish failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($finishException, $throwable);
        }
    }

    /**
     * Create a task callback with the given server settings.
     */
    private function makeCallback(Dispatcher $dispatcher, array $settings = []): TaskCallback
    {
        $settings += [Constant::OPTION_TASK_ENABLE_COROUTINE => false];

        return new TaskCallback($dispatcher, new Repository([
            'server' => ['settings' => $settings],
        ]));
    }
}
