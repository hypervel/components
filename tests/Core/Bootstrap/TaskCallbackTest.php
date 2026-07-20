<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Bootstrap\TaskCallback;
use Hypervel\Core\Events\OnTask;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->makeCallback($dispatcher)->onTask($server, 41, 6, 'payload');
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
