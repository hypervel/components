<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Console\Events;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

class ScheduleWatcher extends Watcher
{
    protected const string LAST_RECORDED_TASK_CONTEXT_KEY = '__telescope.schedule_watcher.last_recorded_task';

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

        if ($event instanceof Events\ScheduledTaskFinished) {
            $this->recordFinishedCommand($event);

            return;
        }

        $this->recordFailedCommand($event);
    }

    /**
     * Record a successfully finished scheduled command.
     */
    protected function recordFinishedCommand(Events\ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $exitCode = $task->exitCode();

        // ScheduleRunCommand::runEvent() follows skipped and nonzero Finished events with Failed.
        if ($task->wasSkippedDueToOverlapping() || ($exitCode !== null && $exitCode !== 0)) {
            return;
        }

        $entry = $this->makeEntry($task, [
            'status' => 'finished',
            'exit_code' => $exitCode,
        ]);

        try {
            Telescope::recordScheduledCommand($entry);
        } finally {
            // afterRecording runs after queueing, and runEvent translates its exception into Failed.
            if (in_array($entry, Telescope::getEntriesQueue(), true)) {
                CoroutineContext::set(static::LAST_RECORDED_TASK_CONTEXT_KEY, [
                    'task' => $this->taskIdentity($task),
                    'entry' => $entry,
                ]);
            }
        }
    }

    /**
     * Record a failed scheduled command.
     */
    protected function recordFailedCommand(Events\ScheduledTaskFailed $event): void
    {
        $outcome = [
            'status' => 'failed',
            'exit_code' => $event->task->exitCode(),
            'exception' => [
                'class' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ],
        ];

        /** @var null|array{task: int, entry: IncomingEntry} $recorded */
        $recorded = CoroutineContext::get(static::LAST_RECORDED_TASK_CONTEXT_KEY);

        if ($recorded !== null
            && $recorded['task'] === $this->taskIdentity($event->task)
            && in_array($recorded['entry'], Telescope::getEntriesQueue(), true)
        ) {
            $recorded['entry']->content = array_merge($recorded['entry']->content, $outcome);

            return;
        }

        Telescope::recordScheduledCommand($this->makeEntry($event->task, $outcome));
    }

    /**
     * Create an entry for the given scheduled command.
     */
    protected function makeEntry(Event $task, array $outcome): IncomingEntry
    {
        return IncomingEntry::make(array_merge([
            'command' => $task instanceof CallbackEvent ? 'Closure' : $task->command,
            'description' => $task->description,
            'expression' => $task->expression,
            'timezone' => $task->timezone,
            'user' => $task->user,
            'output' => $task->getOutput($this->app),
        ], $outcome));
    }

    /**
     * Get the identity for the given scheduled task.
     */
    protected function taskIdentity(Event $task): int
    {
        return spl_object_id($task);
    }
}
