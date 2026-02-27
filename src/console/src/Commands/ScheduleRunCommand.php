<?php

declare(strict_types=1);

namespace Hypervel\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\Events\ScheduledBackgroundTaskFinished;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskSkipped;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Sleep;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'schedule:run')]
class ScheduleRunCommand extends Command
{
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
     * The cache factory implementation.
     */
    protected CacheFactory $cache;

    /**
     * The exception handler.
     */
    protected ExceptionHandler $handler;

    /**
     * The timestamp this scheduler command started running.
     */
    protected ?Carbon $startedAt = null;

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
    protected ?Carbon $lastChecked = null;

    /**
     * The concurrent instance.
     */
    protected ?Concurrent $concurrent = null;

    /**
     * Execute the console command.
     */
    public function handle(
        Schedule $schedule,
        Dispatcher $dispatcher,
        CacheFactory $cache,
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

        $this->clearShouldStop();

        $noEventsAlerted = false;
        while (! $this->shouldStop()) {
            $this->runEvents(
                $this->schedule->dueEvents($this->app),
                Date::now()
            );

            if (! $this->eventsRan && ! $noEventsAlerted && ! $this->option('whisper')) {
                $this->info('No scheduled commands are ready to run, waiting...');
                $noEventsAlerted = true;
            }

            Sleep::usleep(100000);
        }

        $this->stop();
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

        $events = $this->schedule->dueEvents($this->app);

        if ($events->contains->isRepeatable()) {
            $this->clearShouldStop();
        }

        (new Waiter(-1))->wait(function () use ($events) {
            $this->runEvents($events, $this->startedAt);

            if ($events->contains->isRepeatable()) {
                $this->repeatEvents($events->filter->isRepeatable());
            }
        });

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

        while (Date::now()->lte($this->startedAt->endOfMinute())) {
            foreach ($events as $event) {
                if ($this->shouldStop()) {
                    return;
                }

                if (! $event->shouldRepeatNow()) {
                    continue;
                }

                $hasEnteredMaintenanceMode = $hasEnteredMaintenanceMode || $this->app->isDownForMaintenance();

                if ($hasEnteredMaintenanceMode && ! $event->runsInMaintenanceMode()) {
                    continue;
                }

                if (! $event->filtersPass($this->app)) {
                    $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));

                    continue;
                }

                if ($event->onOneServer) {
                    $this->runSingleServerEvent($event, $this->startedAt);
                } else {
                    $this->runEvent($event);
                }

                $this->eventsRan = true;
            }

            Sleep::usleep(100_000);
        }
    }

    protected function runEvents(Collection $events, Carbon $startedAt): void
    {
        foreach ($events as $event) {
            if ($event->lastChecked && ! $event->shouldRepeatNow()) {
                continue;
            }

            if (! $event->filtersPass($this->app)) {
                $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));

                continue;
            }

            $runEvent = fn () => $event->onOneServer
                ? $this->runSingleServerEvent($event, $startedAt)
                : $this->runEvent($event);

            if ($event->runInBackground) {
                $this->concurrent->create(function () use ($runEvent, $event) {
                    $runEvent();
                    $this->dispatcher->dispatch(new ScheduledBackgroundTaskFinished($event));
                });
                continue;
            }

            $runEvent();
        }
    }

    /**
     * Run the given single server event.
     */
    protected function runSingleServerEvent(Event $event, Carbon $startedAt): void
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
            Carbon::now()->format('Y-m-d H:i:s'),
            $command,
            $event->runInBackground ? ' in background (coroutine)' : '',
        );

        $this->eventsRan = true;

        $this->line($description);
        $this->dispatcher->dispatch(new ScheduledTaskStarting($event));

        $start = microtime(true);

        try {
            $event->run($this->app);

            $this->dispatcher->dispatch(new ScheduledTaskFinished(
                $event,
                round(microtime(true) - $start, 2)
            ));

            $this->eventsRan = true;
        } catch (Throwable $e) {
            $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            $this->handler->report($e);
        }

        $finishDescription = sprintf(
            '<fg=gray>%s</> %s [%s] <fg=gray>%sms</>',
            Carbon::now()->format('Y-m-d H:i:s'),
            $event->exitCode == 0 ? '<info>Finished</info>' : '<error>Failed</error>',
            $command,
            round(microtime(true) - $start, 2),
        );

        $this->line($finishDescription);
    }

    /**
     * Determine if the schedule run should be interrupted.
     */
    protected function shouldStop(): bool
    {
        if (! $this->lastChecked) {
            $this->lastChecked = Date::now();
        }

        if ($this->shouldStop || $this->lastChecked->diffInSeconds() < 1) {
            return $this->shouldStop;
        }

        $this->lastChecked = Date::now();

        /* @phpstan-ignore-next-line */
        return $this->shouldStop = $this->cache->get('hypervel:schedule:stop', false);
    }

    /**
     * Clear the stop cache.
     */
    protected function clearShouldStop(): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->delete('hypervel:schedule:stop');

        $this->shouldStop = false;
    }
}
