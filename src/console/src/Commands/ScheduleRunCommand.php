<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Carbon\CarbonInterface;
use Closure;
use Hypervel\Console\Command;
use Hypervel\Console\Events\ScheduledBackgroundTaskFinished;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskSkipped;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Sleep;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'schedule:run')]
class ScheduleRunCommand extends Command
{
    use InteractsWithTime;

    /**
     * The handled failure state for the current task coroutine.
     */
    private const string TASK_FAILED_CONTEXT_KEY = '__console.scheduled_task_failed';

    /**
     * The console command signature.
     */
    protected ?string $signature = 'schedule:run
        {--once : Run only once without looping}
        {--concurrency=60 : The number of background tasks to process at once}
        {--whisper : Do not output message indicating that no commands were ready to run}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Run the scheduled commands';

    /**
     * The schedule instance.
     */
    protected Schedule $schedule;

    /**
     * The event dispatcher.
     */
    protected Dispatcher $dispatcher;

    /**
     * The cache repository implementation.
     */
    protected Cache $cache;

    /**
     * The exception handler.
     */
    protected ExceptionHandler $handler;

    /**
     * The timestamp this scheduler command started running.
     */
    protected ?CarbonInterface $startedAt = null;

    /**
     * Check if any events ran.
     */
    protected bool $eventsRan = false;

    /**
     * Check if scheduler should stop.
     */
    protected bool $shouldStop = false;

    /**
     * Last time the stopped state was checked.
     */
    protected ?CarbonInterface $lastChecked = null;

    /**
     * Check if the scheduler is paused.
     */
    protected bool $paused = false;

    /**
     * Last time the paused state was checked.
     */
    protected ?CarbonInterface $pausedLastChecked = null;

    /**
     * The concurrent instance.
     */
    protected ?Concurrent $concurrent = null;

    /**
     * The minute currently represented by the evaluated event cache.
     */
    protected ?string $evaluatedEventsMinute = null;

    /**
     * The non-repeatable events already evaluated for the current minute.
     *
     * @var array<int, true>
     */
    protected array $evaluatedEvents = [];

    /**
     * The events currently running in this process.
     *
     * @var array<int, array{event: Event, count: int<1, max>}>
     */
    protected array $runningEvents = [];

    /**
     * Execute the console command.
     */
    public function handle(
        Schedule $schedule,
        Dispatcher $dispatcher,
        Cache $cache,
        ExceptionHandler $handler,
    ) {
        $this->schedule = $schedule;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
        $this->handler = $handler;

        $this->concurrent = new Concurrent(
            (int) $this->option('concurrency')
        );

        $this->newLine();

        if ($this->option('once') ?: false) {
            $this->runOnce();
            return;
        }

        $this->listenForSignals();

        try {
            $this->clearShouldStop();

            $noEventsAlerted = false;
            while (! $this->shouldStop()) {
                $startedAt = Date::now();

                $this->runEvents(
                    $this->schedule->dueEventsAt($this->hypervel, $startedAt),
                    $startedAt
                );

                if (! $this->eventsRan && ! $noEventsAlerted && ! $this->option('whisper')) {
                    $this->info('No scheduled commands are ready to run, waiting...');
                    $noEventsAlerted = true;
                }

                Sleep::usleep(100000);
            }

            $this->stop();
        } finally {
            $this->untrap();
        }
    }

    protected function stop(): void
    {
        $this->info('Stopping the scheduling...');

        while (true) {
            if ($this->concurrent->isEmpty()) {
                $this->info('Done.');
                break;
            }

            Sleep::usleep(100000);
        }
    }

    /**
     * Run the scheduled events once.
     */
    protected function runOnce(): void
    {
        $this->startedAt = Date::now();

        $events = $this->schedule->dueEventsAt($this->hypervel, $this->startedAt);

        if ($events->contains->isRepeatable()) {
            $this->clearShouldStop();
        }

        // The finite --once path runs inside Waiter; signal-wait coroutines keep
        // that scheduler coroutine alive, so mutex signal cleanup is long-run only.
        (new Waiter(-1))->wait(function () use ($events) {
            $this->runEvents($events, $this->startedAt);

            if ($events->contains->isRepeatable()) {
                $this->repeatEvents($events->filter->isRepeatable());
            }
        }, copyContext: [ContextRepository::CONTEXT_KEY]);

        if (! $this->eventsRan && ! $this->option('whisper')) {
            $this->info('No scheduled commands are ready to run.');
        }
    }

    /**
     * Run the given repeating events for the remainder of the current minute.
     */
    protected function repeatEvents(Collection $events): void
    {
        $hasEnteredMaintenanceMode = false;
        $endOfMinute = $this->startedAt->copy()->endOfMinute();

        while (Date::now()->lte($endOfMinute)) {
            $paused = $this->isPaused();

            foreach ($events as $event) {
                if ($this->shouldStop()) {
                    return;
                }

                if (! $event->shouldRepeatNow()) {
                    continue;
                }

                if (Date::now()->gt($endOfMinute)) {
                    return;
                }

                $hasEnteredMaintenanceMode = $hasEnteredMaintenanceMode || $this->hypervel->isDownForMaintenance();

                if ($hasEnteredMaintenanceMode && ! $event->runsInMaintenanceMode()) {
                    continue;
                }

                $this->runTaskInCoroutine(function () use ($event, $paused): void {
                    if ($paused && ! $event->runsWhenPaused()) {
                        $event->lastChecked = Date::now();
                        $this->dispatchTaskSkipped($event);

                        return;
                    }

                    if (! $event->filtersPass($this->hypervel)) {
                        $this->dispatchTaskSkipped($event);

                        return;
                    }

                    $this->runScheduledEvent($event, $this->startedAt);

                    $this->eventsRan = true;
                });
            }

            Sleep::usleep(100_000);
        }
    }

    protected function runEvents(Collection $events, CarbonInterface $startedAt): void
    {
        $paused = $this->isPaused();

        foreach ($events as $event) {
            if ($event->isRepeatable() && $event->lastChecked && ! $event->shouldRepeatNow()) {
                continue;
            }

            if ($this->hasAlreadyEvaluatedNonRepeatableEvent($event, $startedAt)) {
                continue;
            }

            $this->runTaskInCoroutine(function () use ($event, $startedAt, $paused): void {
                if ($paused && ! $event->runsWhenPaused()) {
                    $event->lastChecked = Date::now();
                    $this->dispatchTaskSkipped($event);

                    return;
                }

                if (! $event->filtersPass($this->hypervel)) {
                    $this->dispatchTaskSkipped($event);

                    return;
                }

                $this->runScheduledEvent($event, $startedAt);

                $this->eventsRan = true;
            });
        }
    }

    /**
     * Run user task evaluation in a finite coroutine.
     */
    protected function runTaskInCoroutine(Closure $callback): void
    {
        (new Waiter(-1))->wait(
            fn () => $this->runTask($callback),
            copyContext: [ContextRepository::CONTEXT_KEY],
        );
    }

    /**
     * Run a task and its deferred callbacks in the owning coroutine.
     *
     * @throws Throwable
     */
    private function runTask(Closure $callback): void
    {
        $exception = null;

        try {
            $callback();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        // Nested commands leave deferred work to this boundary. Drain only once,
        // after task listeners, so callbacks cannot run early or recursively.
        if (! $exception instanceof CanceledException) {
            try {
                $this->invokeDeferredCallbacks(
                    $exception === null && ! CoroutineContext::get(self::TASK_FAILED_CONTEXT_KEY, false)
                );
            } catch (CanceledException $cancellation) {
                $exception = $cancellation;
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Invoke deferred callbacks allowed by the task's outcome.
     */
    private function invokeDeferredCallbacks(bool $successful): void
    {
        $container = Container::getInstance();

        if ($container->resolvedScoped(DeferredCallbackCollection::class)) {
            $container->make(DeferredCallbackCollection::class)
                ->invokeWhen(fn (DeferredCallback $callback): bool => $successful || $callback->always);
        }
    }

    /**
     * Dispatch a scheduled event in the foreground or background.
     */
    protected function runScheduledEvent(Event $event, CarbonInterface $startedAt): void
    {
        $runEvent = fn () => $event->onOneServer
            ? $this->runSingleServerEvent($event, $startedAt)
            : $this->runEvent($event);

        if ($event->runInBackground) {
            $this->concurrent->fork(
                fn () => $this->runTask($runEvent),
                [ContextRepository::CONTEXT_KEY],
            );

            return;
        }

        $runEvent();
    }

    /**
     * Determine if the non-repeatable event was already evaluated this minute.
     */
    protected function hasAlreadyEvaluatedNonRepeatableEvent(Event $event, CarbonInterface $startedAt): bool
    {
        if ($event->isRepeatable()) {
            return false;
        }

        $minute = $startedAt->format('YmdHi');

        if ($this->evaluatedEventsMinute !== $minute) {
            $this->evaluatedEventsMinute = $minute;
            $this->evaluatedEvents = [];
        }

        $eventId = spl_object_id($event);

        if (isset($this->evaluatedEvents[$eventId])) {
            return true;
        }

        $this->evaluatedEvents[$eventId] = true;

        return false;
    }

    /**
     * Run the given single server event.
     */
    protected function runSingleServerEvent(Event $event, CarbonInterface $startedAt): void
    {
        if ($this->schedule->serverShouldRun($event, $startedAt)) {
            $this->runEvent($event);
        } else {
            $this->info(sprintf(
                'Skipping [%s], as command already run on another server.',
                $event->getSummaryForDisplay()
            ));
        }
    }

    /**
     * Run the given event.
     */
    protected function runEvent(Event $event): void
    {
        $summary = $event->getSummaryForDisplay();

        $command = $event instanceof CallbackEvent
            ? $summary
            : $event->command;

        $description = sprintf(
            '<fg=gray>%s</> Running [%s]%s',
            CarbonImmutable::now()->format('Y-m-d H:i:s'),
            $command,
            $event->runInBackground ? ' in background (coroutine)' : '',
        );

        $this->eventsRan = true;

        $this->line($description);

        if ($this->dispatcher->hasListeners(ScheduledTaskStarting::class)) {
            $this->dispatcher->dispatch(new ScheduledTaskStarting($event));
        }

        $start = microtime(true);

        $this->registerRunningEvent($event);

        $failed = false;
        $skippedBecauseOverlapping = false;

        try {
            $event->run($this->hypervel);
            $skippedBecauseOverlapping = $event->wasSkippedDueToOverlapping();

            if ($this->dispatcher->hasListeners(ScheduledTaskFinished::class)) {
                $this->dispatcher->dispatch(new ScheduledTaskFinished(
                    $event,
                    round(microtime(true) - $start, 2)
                ));
            }

            $this->eventsRan = true;

            $exitCode = $event->exitCode();

            if (
                ! $skippedBecauseOverlapping
                && $exitCode !== null
                && $exitCode !== 0
            ) {
                throw new RuntimeException(
                    "Scheduled command [{$command}] failed with exit code [{$exitCode}]."
                );
            }
        } catch (CanceledException $e) {
            throw $e;
        } catch (Throwable $e) {
            $failed = true;
            // runEvent reports ordinary failures instead of throwing them to
            // runTask; keep that outcome local to this task's deferred work.
            CoroutineContext::set(self::TASK_FAILED_CONTEXT_KEY, true);

            if ($this->dispatcher->hasListeners(ScheduledTaskFailed::class)) {
                $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            }

            $this->handler->report($e);
        } finally {
            $this->forgetRunningEvent($event);
        }

        $status = match (true) {
            $failed => '<error>Failed</error>',
            $skippedBecauseOverlapping => '<comment>Skipped</comment>',
            $event->exitCode() === 0 => '<info>Finished</info>',
            default => '<error>Failed</error>',
        };

        $finishDescription = sprintf(
            '<fg=gray>%s</> %s [%s] <fg=gray>%s</>',
            CarbonImmutable::now()->format('Y-m-d H:i:s'),
            $status,
            $command,
            $this->runTimeForHumans($start),
        );

        $this->line($finishDescription);

        if ($event->runInBackground
            && ! $skippedBecauseOverlapping
            && $this->dispatcher->hasListeners(ScheduledBackgroundTaskFinished::class)
        ) {
            $this->dispatcher->dispatch(new ScheduledBackgroundTaskFinished($event));
        }
    }

    /**
     * Determine if the schedule is paused.
     */
    protected function isPaused(): bool
    {
        if (! Schedule::$pausable) {
            return false;
        }

        if ($this->pausedLastChecked && abs($this->pausedLastChecked->diffInSeconds()) < 1) {
            return $this->paused;
        }

        $this->pausedLastChecked = Date::now();

        return $this->paused = (bool) $this->cache->get('hypervel:schedule:paused', false);
    }

    /**
     * Determine if the schedule run should be interrupted.
     */
    protected function shouldStop(): bool
    {
        if (! Schedule::$interruptible) {
            return false;
        }

        if (! $this->lastChecked) {
            $this->lastChecked = Date::now();
        }

        if ($this->shouldStop || abs($this->lastChecked->diffInSeconds()) < 1) {
            return $this->shouldStop;
        }

        $this->lastChecked = Date::now();

        return $this->shouldStop = (bool) $this->cache->get('hypervel:schedule:interrupt', false);
    }

    /**
     * Clear the interrupt cache.
     */
    protected function clearShouldStop(): void
    {
        $this->cache->forget('hypervel:schedule:interrupt');

        $this->shouldStop = false;
    }

    /**
     * Listen for signals that should release owned event mutexes.
     */
    protected function listenForSignals(): void
    {
        $this->trap([SIGTERM, SIGINT, SIGQUIT], fn () => $this->releaseRunningEventMutexes());
    }

    /**
     * Register a running event for signal cleanup.
     */
    protected function registerRunningEvent(Event $event): void
    {
        $eventId = spl_object_id($event);

        if (isset($this->runningEvents[$eventId])) {
            ++$this->runningEvents[$eventId]['count'];

            return;
        }

        $this->runningEvents[$eventId] = ['event' => $event, 'count' => 1];
    }

    /**
     * Forget a running event after it finishes.
     */
    protected function forgetRunningEvent(Event $event): void
    {
        $eventId = spl_object_id($event);

        if (! isset($this->runningEvents[$eventId])) {
            return;
        }

        if ($this->runningEvents[$eventId]['count'] > 1) {
            --$this->runningEvents[$eventId]['count'];

            return;
        }

        unset($this->runningEvents[$eventId]);
    }

    /**
     * Release mutexes for events that are currently running.
     */
    protected function releaseRunningEventMutexes(): void
    {
        foreach ($this->runningEvents as $runningEvent) {
            $runningEvent['event']->releaseMutexOnTerminationSignal();
        }
    }

    /**
     * Dispatch the scheduled task skipped event when listeners are registered.
     */
    protected function dispatchTaskSkipped(Event $event): void
    {
        if ($this->dispatcher->hasListeners(ScheduledTaskSkipped::class)) {
            $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));
        }
    }
}
