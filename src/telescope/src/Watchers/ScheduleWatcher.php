<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Console\Events;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

class ScheduleWatcher extends Watcher
{
    protected const LAST_RECORDED_TASK_CONTEXT_KEY = '__telescope.schedule_watcher.last_recorded_task';

    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $this->app = $app;

        $app->make(Dispatcher::class)
            ->listen([
                Events\ScheduledTaskFinished::class,
                Events\ScheduledTaskFailed::class,
            ], [$this, 'recordCommand']);
    }

    /**
     * Record a scheduled command that was executed.
     */
    public function recordCommand(Events\ScheduledTaskFailed|Events\ScheduledTaskFinished $event): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        $task = $event->task;
        $taskId = spl_object_id($task);

        if (CoroutineContext::get(static::LAST_RECORDED_TASK_CONTEXT_KEY) === $taskId) {
            return;
        }

        CoroutineContext::set(static::LAST_RECORDED_TASK_CONTEXT_KEY, $taskId);

        Telescope::recordScheduledCommand(IncomingEntry::make([
            'command' => $task instanceof CallbackEvent ? 'Closure' : $task->command,
            'description' => $task->description,
            'expression' => $task->expression,
            'timezone' => $task->timezone,
            'user' => $task->user,
            'output' => $task->getOutput($this->app),
        ]));
    }
}
