<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Bootstrap\TaskCallback;
use Hypervel\Core\Events\OnTask;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Constant;
use Swoole\Server;
use Swoole\Server\Task;

class TaskCallbackTest extends TestCase
{
    use VerifiesDoubles;

    public function testLegacySignatureBuildsTaskEvent(): void
    {
        $server = Double::for(Server::class);
        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event) use ($server): void {
                $this->assertSame($server, $event->server);
                $this->assertSame(12, $event->task->id);
                $this->assertSame(3, $event->task->worker_id);
                $this->assertSame(['payload' => true], $event->task->data);
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(false);

        $this->makeCallback($dispatcher)->onTask($server, 12, 3, ['payload' => true]);
    }

    public function testMissingTaskListenerSkipsFinishAndStillDispatchesTerminalEvent(): void
    {
        $server = Double::for(Server::class);
        $server->expects('finish')->never();

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(TaskTerminated::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class))
            ->resolves(function (TaskTerminated $event) use ($server): void {
                $this->assertSame($server, $event->server);
                $this->assertSame(12, $event->task->id);
                $this->assertSame(3, $event->task->worker_id);
                $this->assertSame(['payload' => true], $event->task->data);
            });

        $this->makeCallback($dispatcher)->onTask($server, 12, 3, ['payload' => true]);
    }

    #[DataProvider('objectModeSettings')]
    public function testDedicatedTaskSettingsUseNativeObjectSignature(array $settings): void
    {
        $server = Double::for(Server::class);
        $task = new Task;
        $task->id = 21;
        $task->worker_id = 4;
        $task->data = 'payload';

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event) use ($server, $task): void {
                $this->assertSame($server, $event->server);
                $this->assertSame($task, $event->task);
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class))
            ->resolves(function (TaskTerminated $event) use ($server, $task): void {
                $this->assertSame($server, $event->server);
                $this->assertSame($task, $event->task);
            });

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
        $server = Double::for(Server::class);
        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event): void {
                $this->assertSame(31, $event->task->id);
                $this->assertSame('legacy', $event->task->data);
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(false);

        $this->makeCallback($dispatcher, [
            Constant::OPTION_TASK_USE_OBJECT => false,
            Constant::OPTION_TASK_OBJECT => true,
        ])->onTask($server, 31, 5, 'legacy');
    }

    public function testLegacyResultIsFinishedThroughServer(): void
    {
        $server = Double::for(Server::class);
        $server->expects('finish')->with('completed');

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event): void {
                $event->setResult('completed');
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(false);

        $this->makeCallback($dispatcher)->onTask($server, 41, 6, 'payload');
    }

    public function testLegacyResultIsFinishedBeforeTerminalDispatch(): void
    {
        $order = [];
        $server = Double::for(Server::class);
        $server->expects('finish')
            ->with('completed')
            ->resolves(function () use (&$order): bool {
                $order[] = 'finish';

                return true;
            });

        $task = null;
        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event) use (&$order, &$task): void {
                $task = $event->task;
                $event->setResult('completed');
                $order[] = 'task';
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class))
            ->resolves(function (TaskTerminated $event) use ($server, &$order, &$task): void {
                $this->assertSame($server, $event->server);
                $this->assertSame($task, $event->task);
                $order[] = 'terminated';
            });

        $this->makeCallback($dispatcher)->onTask($server, 42, 7, 'payload');

        $this->assertSame(['task', 'finish', 'terminated'], $order);
    }

    public function testTaskFailureSkipsFinishButStillDispatchesTerminalEvent(): void
    {
        $exception = new RuntimeException('Task failed.');
        $server = Double::for(Server::class);
        $server->expects('finish')->never();

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->throws($exception);
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class));

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
        $server = Double::for(Server::class);
        $server->expects('finish')->throws($exception);

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event): void {
                $event->setResult('completed');
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class));

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
        $server = Double::for(Server::class);
        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class));
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class))
            ->throws($exception);

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
        $server = Double::for(Server::class);
        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->throws($taskException);
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class))
            ->throws($terminalException);

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
        $server = Double::for(Server::class);
        $server->expects('finish')->throws($finishException);

        $dispatcher = Double::for(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(OnTask::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(OnTask::class))
            ->resolves(function (OnTask $event): void {
                $event->setResult('completed');
            });
        $dispatcher->expects('hasListeners')
            ->with(TaskTerminated::class)
            ->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(TaskTerminated::class))
            ->throws($terminalException);

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
