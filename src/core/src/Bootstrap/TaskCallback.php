<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnTask;
use Swoole\Constant;
use Swoole\Server;
use Swoole\Server\Task;

class TaskCallback
{
    protected bool $taskUsesObject;

    public function __construct(protected Dispatcher $dispatcher, Repository $config)
    {
        $settings = $config->array('server.settings');
        $taskObject = array_key_exists(Constant::OPTION_TASK_USE_OBJECT, $settings)
            ? (bool) $settings[Constant::OPTION_TASK_USE_OBJECT]
            : (bool) ($settings[Constant::OPTION_TASK_OBJECT] ?? false);

        $this->taskUsesObject = $config->boolean('server.settings.' . Constant::OPTION_TASK_ENABLE_COROUTINE)
            || $taskObject;
    }

    /**
     * Handle the task event.
     */
    public function onTask(Server $server, mixed ...$arguments): void
    {
        if ($this->taskUsesObject) {
            /** @var Task $task */
            $task = $arguments[0];
        } else {
            [$taskId, $srcWorkerId, $data] = $arguments;
            $task = new Task;
            $task->id = $taskId;
            $task->worker_id = $srcWorkerId;
            $task->data = $data;
        }

        $event = new OnTask($server, $task);
        $this->dispatcher->dispatch($event);

        if (! is_null($event->result)) {
            if ($this->taskUsesObject) {
                $task->finish($event->result);
            } else {
                $server->finish($event->result);
            }
        }
    }
}
