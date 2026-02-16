<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Framework\Events\OnTask;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;
use Swoole\Server\Task;

class TaskCallback
{
    protected bool $taskEnableCoroutine = false;

    public function __construct(protected EventDispatcherInterface $dispatcher, Repository $config)
    {
        $this->taskEnableCoroutine = (bool) $config->get('server.settings.task_enable_coroutine', false);
    }

    /**
     * Handle the task event.
     */
    public function onTask(Server $server, mixed ...$arguments): void
    {
        if ($this->taskEnableCoroutine) {
            $task = $arguments[0];
        } else {
            [$taskId, $srcWorkerId, $data] = $arguments;
            $task = new Task();
            $task->id = $taskId;
            $task->worker_id = $srcWorkerId;
            $task->data = $data;
        }

        $event = $this->dispatcher->dispatch(new OnTask($server, $task));

        if ($event instanceof OnTask && ! is_null($event->result)) {
            if ($this->taskEnableCoroutine) {
                $task->finish($event->result);
            } else {
                $server->finish($event->result);
            }
        }
    }
}
