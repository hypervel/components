<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Events\CommandStarting;
use Hypervel\Console\Events\ScheduledTaskCanceled;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event as SchedulingEvent;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Features\Concerns\WorksWithSpans;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use RuntimeException;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event as SentryEvent;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Throwable;
use WeakMap;

class ConsoleSchedulingIntegration extends Feature
{
    use TracksPushedScopesAndSpans;
    use WorksWithSpans;

    /**
     * The context key for the check-ins in progress in the current coroutine.
     */
    public const string CHECK_INS_CONTEXT_KEY = '__sentry.scheduling.check_ins';

    private bool $shouldHandleCheckIn = false;

    /**
     * The scheduled tasks that `sentryMonitor()` was called on, with their explicit slug or `false`.
     *
     * Weak keys let schedules built by other `resolveConsoleSchedule()` calls be freed.
     *
     * @var WeakMap<SchedulingEvent, false|string>
     */
    private WeakMap $monitoredTasks;

    /**
     * Register the `sentryMonitor` scheduling macro.
     */
    public function register(): void
    {
        $this->monitoredTasks = new WeakMap;
        $monitoredTasks = $this->monitoredTasks;

        $startCheckIn = function (
            ?string $slug,
            SchedulingEvent $scheduled,
            ?int $checkInMargin,
            ?int $maxRuntime,
            bool $updateMonitorConfig,
            ?int $failureIssueThreshold,
            ?int $recoveryThreshold,
            ?string $schedule
        ): void {
            $this->startCheckIn(
                $slug,
                $scheduled,
                $checkInMargin,
                $maxRuntime,
                $updateMonitorConfig,
                $failureIssueThreshold,
                $recoveryThreshold,
                $schedule
            );
        };
        $finishCheckIn = function (?string $slug, SchedulingEvent $scheduled, CheckInStatus $status): void {
            $this->finishCheckIn($slug, $scheduled, $status);
        };

        SchedulingEvent::macro('sentryMonitor', function (
            ?string $monitorSlug = null,
            ?int $checkInMargin = null,
            ?int $maxRuntime = null,
            bool $updateMonitorConfig = true,
            ?int $failureIssueThreshold = null,
            ?int $recoveryThreshold = null,
            ?string $schedule = null
        ) use ($startCheckIn, $finishCheckIn, $monitoredTasks): SchedulingEvent {
            /** @var SchedulingEvent $this */
            if ($monitorSlug === null && empty($this->command) && empty($this->description)) {
                throw new RuntimeException(
                    'The command and description are not set, please set a slug manually for this scheduled command using the `sentryMonitor(\'your-monitor-slug\')` macro.'
                );
            }

            // A WeakMap treats a null value as missing, so an implicit slug is stored as false.
            $monitoredTasks[$this] = $monitorSlug ?? false;

            return $this
                ->before(function () use (
                    $startCheckIn,
                    $monitorSlug,
                    $checkInMargin,
                    $maxRuntime,
                    $updateMonitorConfig,
                    $failureIssueThreshold,
                    $recoveryThreshold,
                    $schedule
                ): void {
                    /** @var SchedulingEvent $this */
                    $startCheckIn(
                        $monitorSlug,
                        $this,
                        $checkInMargin,
                        $maxRuntime,
                        $updateMonitorConfig,
                        $failureIssueThreshold,
                        $recoveryThreshold,
                        $schedule
                    );
                })
                ->onSuccess(function () use ($finishCheckIn, $monitorSlug): void {
                    /** @var SchedulingEvent $this */
                    $finishCheckIn($monitorSlug, $this, CheckInStatus::ok());
                })
                ->onFailure(function () use ($finishCheckIn, $monitorSlug): void {
                    /** @var SchedulingEvent $this */
                    $finishCheckIn($monitorSlug, $this, CheckInStatus::error());
                });
        });
    }

    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return true;
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        $this->shouldHandleCheckIn = true;

        $dispatcher = $this->container->make('events');

        // A failed or canceled run can skip the after callbacks that finish its check-in.
        $dispatcher->listen(ScheduledTaskFailed::class, [$this, 'handleScheduledTaskFailed']);
        $dispatcher->listen(ScheduledTaskCanceled::class, [$this, 'handleScheduledTaskCanceled']);

        if ($this->getUserConfig()['crons']['monitor_scheduled_tasks'] === true) {
            $dispatcher->listen(CommandStarting::class, [$this, 'handleCommandStarting']);
        }

        if (! $this->canRecordSpans()) {
            return;
        }

        $dispatcher->listen(ScheduledTaskStarting::class, [$this, 'handleScheduledTaskStarting']);
        $dispatcher->listen(ScheduledTaskFinished::class, [$this, 'handleScheduledTaskFinished']);
    }

    /**
     * Call `sentryMonitor()` on every scheduled task before the scheduler runs them.
     */
    public function handleCommandStarting(CommandStarting $event): void
    {
        // Unlike upstream, there is no `schedule:finish`: background tasks finish in the scheduler process.
        if (! in_array($event->command, ['schedule:run', 'schedule:test'], true)) {
            return;
        }

        try {
            $this->monitorScheduledTasks($this->container->make(Schedule::class));
        } catch (Throwable $exception) {
            // Resolving the schedule runs the application's schedule definitions, which a cancellation can interrupt.
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            SentrySdk::getCurrentHub()->getClient()?->getOptions()->getLoggerOrNullLogger()
                ->warning('Failed to set up cron monitoring for scheduled tasks.', ['exception' => $exception]);
        }
    }

    /**
     * Set up the feature in the environment in an inactive state (when no DSN was set).
     */
    public function onBootInactive(): void
    {
        $this->shouldHandleCheckIn = false;
    }

    // Upstream's useCacheStore() and its cache and Context hand-off of check-in IDs are not ported:
    // background tasks run in a coroutine of the scheduler process, which starts and finishes their check-ins.

    /**
     * Start tracing the scheduled task.
     */
    public function handleScheduledTaskStarting(ScheduledTaskStarting $event): void
    {
        // When scheduling a command class the command name will be the most descriptive
        // When a job is scheduled the command name is `null` and the job class name (or display name) is set as the description
        // When a closure is scheduled both the command name and description are `null`
        $name = $this->getCommandNameForScheduled($event->task) ?? $event->task->description ?? 'Closure';

        $context = TransactionContext::make()
            ->setName($name)
            ->setSource(TransactionSource::task())
            ->setOp('console.command.scheduled')
            ->setStartTimestamp(microtime(true));

        $transaction = SentrySdk::getCurrentHub()->startTransaction($context);
        $this->pushSpan($transaction);
    }

    /**
     * Finish tracing the scheduled task with its published outcome.
     */
    public function handleScheduledTaskFinished(ScheduledTaskFinished $event): void
    {
        $this->maybeFinishSpan($this->finishedStatus($event->task));
    }

    /**
     * Mark tracing and the unfinished check-ins of the scheduled task as failed.
     */
    public function handleScheduledTaskFailed(): void
    {
        $this->maybeFinishSpan(SpanStatus::internalError());
        $this->failUnfinishedCheckIns();
    }

    /**
     * Finish tracing the scheduled task that cancellation stopped before its outcome was published.
     *
     * A direct cancellation leaves an unfinished check-in without a terminal status: the SDK has no
     * canceled status, and the cancellation is not a task failure.
     */
    public function handleScheduledTaskCanceled(ScheduledTaskCanceled $event): void
    {
        $status = match (true) {
            $event->exception !== null => SpanStatus::internalError(),
            // A task that finished running keeps its outcome; the cancellation came from a listener.
            $event->runtime !== null => $this->finishedStatus($event->task),
            default => $this->cancellationStatus($event->cancellation),
        };

        $this->maybeFinishSpan($status);

        if ($status === SpanStatus::internalError()) {
            $this->failUnfinishedCheckIns();
        }
    }

    /**
     * Get the span status of a scheduled task that finished running.
     */
    private function finishedStatus(SchedulingEvent $task): SpanStatus
    {
        $exitCode = $task->exitCode();

        return $exitCode === null || $exitCode === 0
            ? SpanStatus::ok()
            : SpanStatus::internalError();
    }

    /**
     * Monitor every scheduled task that is not monitored, excluded or ambiguous.
     */
    private function monitorScheduledTasks(Schedule $schedule): void
    {
        $excluded = $this->getUserConfig()['crons']['exclude_scheduled_tasks'];
        $monitoredSlugs = [];
        $tasksBySlug = [];
        $taskSlugs = [];

        foreach ($schedule->events() as $task) {
            if (isset($this->monitoredTasks[$task])) {
                $explicitSlug = $this->monitoredTasks[$task];
                $monitoredSlugs[$explicitSlug === false ? $this->makeSlugForScheduled($task) : $explicitSlug] = true;

                continue;
            }

            // Closures without a name have nothing to make a stable slug from
            if (empty($task->command) && empty($task->description)) {
                continue;
            }

            $slug = $this->makeSlugForScheduled($task);
            // Sentry cuts slugs at 50 characters
            $taskSlug = strlen($slug) > 50 ? substr($slug, 0, 41) . '_' . hash('crc32b', $slug) : $slug;

            if (! $this->isExcluded($task, $slug, $taskSlug, $excluded)) {
                $tasksBySlug[$slug][] = $task;
                $taskSlugs[$slug] = $taskSlug;
            }
        }

        foreach ($tasksBySlug as $slug => $tasks) {
            $taskSlug = $taskSlugs[$slug];

            // Task order isn't stable, so a shared slug can't be given to any one of the tasks
            if (count($tasks) > 1 || isset($monitoredSlugs[$slug]) || isset($monitoredSlugs[$taskSlug])) {
                SentrySdk::getCurrentHub()->getClient()?->getOptions()->getLoggerOrNullLogger()->warning(sprintf(
                    'Not monitoring scheduled tasks with the slug "%s" because more than one task uses it. Give each task a unique slug with `->sentryMonitor(\'your-monitor-slug\')`.',
                    $slug
                ));

                continue;
            }

            $crontab = $this->getMonitorCrontab($tasks[0]);

            if ($crontab === null) {
                SentrySdk::getCurrentHub()->getClient()?->getOptions()->getLoggerOrNullLogger()->debug(sprintf(
                    'Not monitoring the scheduled task "%s" because Sentry can\'t match its schedule.',
                    $slug
                ));

                continue;
            }

            $tasks[0]->sentryMonitor($taskSlug, null, null, true, null, null, $crontab);
        }
    }

    /**
     * Determine if the scheduled task matches an excluded slug, command or description.
     *
     * @param array<int, string> $excluded
     */
    private function isExcluded(SchedulingEvent $task, string $slug, string $taskSlug, array $excluded): bool
    {
        // Unlike upstream, the shortened slug that Sentry shows is matched too
        foreach ([$slug, $taskSlug, $this->getCommandNameForScheduled($task), $task->description] as $name) {
            if ($name !== null && $name !== '' && Str::is($excluded, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the crontab Sentry should expect, or null when Sentry would expect runs that the task skips or reads it differently.
     */
    private function getMonitorCrontab(SchedulingEvent $task): ?string
    {
        $hasConditions = (function (): bool {
            /** @var SchedulingEvent $this */
            // `withoutOverlapping()` adds a reject, but an overlap means the previous run is late anyway
            return count($this->filters) > 0 || count($this->rejects) > ($this->withoutOverlapping ? 1 : 0);
        })->call($task);

        // Without a timezone the check-in is read as UTC, and Sentry needs a timezone name, not an offset
        $timezone = $task->timezone instanceof DateTimeZone ? $task->timezone : new DateTimeZone($task->timezone ?? 'UTC');
        $isTimezoneSupported = $timezone->getLocation() !== false && ($task->timezone !== null || date_default_timezone_get() === 'UTC');

        // Sub-minute tasks (`everyTenSeconds()`) can't be expressed as a crontab
        if ($hasConditions || $task->repeatSeconds !== null || ! $isTimezoneSupported) {
            return null;
        }

        // cron-expression reads "?" as "*"
        $fields = preg_split('/\s+/', trim(str_replace('?', '*', $task->getExpression())));

        if ($fields === false || count($fields) !== 5) {
            return null;
        }

        // With both day fields restricted, cron-expression runs on either day, while Sentry's parser depends on a leading "*"
        if ($fields[2] !== '*' && $fields[4] !== '*') {
            return null;
        }

        // Unlike upstream, a day of month equal to this month's last day stays monitored: Hypervel's
        // `lastDayOfMonth()` writes "L" rather than the day number, so the crontab doesn't change each month.

        foreach ($fields as $position => $field) {
            foreach (explode(',', $field) as $item) {
                // Only items cron-expression and Sentry read the same way; a step from a number such as "5/15" isn't one
                $isSupported = preg_match('/^(?:(?:\*|\d+-\d+)(?:\/\d+)?|\d+|[a-z]{3}(?:-[a-z]{3})?)$/i', $item)
                    || ($position === 2 && $item === 'L')
                    || ($position === 4 && preg_match('/^(?:\d(?:L|#[1-5])|[a-z]{3}#[1-5])$/i', $item));

                if (! $isSupported) {
                    return null;
                }

                if (preg_match('/^(?:\*|(\d+)-(\d+))(?:\/(\d+))?$/', $item, $range) === 1) {
                    [, $start, $end, $step] = $range + ['', '', '', ''];

                    // The parsers also differ on a range of one value, a step as large as the field's last
                    // value, and a stepped weekday range ending at 7: Sentry reads "0-7/2" with Sundays
                    if (($start !== '' && (int) $start === (int) $end)
                        || ($step !== '' && ((int) $step >= [59, 23, 31, 12, 7][$position] || ($position === 4 && (int) $end === 7)))) {
                        return null;
                    }
                }
            }
        }

        // Like Debian cron, Sentry expects a task with a set minute and hour that a clock change skips to run
        // when the change ends, which the scheduler doesn't always do. Clock changes fall on the same weekday
        // each year but not the same date, so only the time and weekday are compared, unless the weekday
        // depends on the date, as "5L" and "1#2" do.
        if (! str_starts_with($fields[0], '*') && ! str_starts_with($fields[1], '*')) {
            $weekday = preg_match('/[L#]/i', $fields[4]) === 1 ? '*' : $fields[4];

            if ($this->runsLateAfterClockChange(new CronExpression("{$fields[0]} {$fields[1]} * * {$weekday}"), $timezone)) {
                return null;
            }
        }

        return implode(' ', $fields);
    }

    /**
     * Determine if the schedule falls in local times that a clock change skips, but isn't due when the change ends.
     */
    private function runsLateAfterClockChange(CronExpression $cron, DateTimeZone $timezone): bool
    {
        $now = Date::now()->getTimestamp();
        // Two years always include each yearly change, which can be up to 371 days apart
        $transitions = $timezone->getTransitions($now, $now + 2 * 366 * 86400);

        for ($index = 1, $count = count($transitions); $index < $count; ++$index) {
            $previousOffset = $transitions[$index - 1]['offset'];
            $change = $transitions[$index];

            if ($change['offset'] <= $previousOffset) {
                continue;
            }

            // The skipped local times run from the old offset up to the new one, compared here as UTC times
            $firstMatch = $cron->getNextRunDate(new DateTimeImmutable('@' . ($change['ts'] + $previousOffset)), allowCurrentDate: true);

            if ($firstMatch->getTimestamp() < $change['ts'] + $change['offset']
                && ! $cron->isDue((new DateTimeImmutable('@' . $change['ts']))->setTimezone($timezone))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send the in-progress check-in for the scheduled event.
     */
    private function startCheckIn(
        ?string $slug,
        SchedulingEvent $scheduled,
        ?int $checkInMargin,
        ?int $maxRuntime,
        bool $updateMonitorConfig,
        ?int $failureIssueThreshold,
        ?int $recoveryThreshold,
        ?string $schedule
    ): void {
        if (! $this->shouldHandleCheckIn) {
            return;
        }

        $checkInSlug = $slug ?? $this->makeSlugForScheduled($scheduled);

        $checkIn = $this->createCheckIn($checkInSlug, CheckInStatus::inProgress());

        if ($updateMonitorConfig || $slug === null) {
            $timezone = $scheduled->timezone;

            if ($timezone instanceof DateTimeZone) {
                $timezone = $timezone->getName();
            }

            $checkIn->setMonitorConfig(
                new MonitorConfig(
                    MonitorSchedule::crontab($schedule ?? $scheduled->getExpression()),
                    $checkInMargin,
                    $maxRuntime,
                    $timezone,
                    $failureIssueThreshold,
                    $recoveryThreshold
                )
            );
        }

        $cacheKey = $this->buildCacheKey($scheduled->mutexName(), $checkInSlug);

        // Background tasks finish in the coroutine that started them, so the check-in never needs to
        // leave it. The application log context would carry it into queued jobs and subprocesses.
        $checkIns = CoroutineContext::get(self::CHECK_INS_CONTEXT_KEY, []);
        $checkIns[$cacheKey] = $checkIn;
        CoroutineContext::set(self::CHECK_INS_CONTEXT_KEY, $checkIns);

        $this->sendCheckIn($checkIn);
    }

    /**
     * Send the final check-in for the scheduled event with the given status.
     */
    private function finishCheckIn(?string $slug, SchedulingEvent $scheduled, CheckInStatus $status): void
    {
        if (! $this->shouldHandleCheckIn) {
            return;
        }

        $checkInSlug = $slug ?? $this->makeSlugForScheduled($scheduled);

        $cacheKey = $this->buildCacheKey($scheduled->mutexName(), $checkInSlug);

        $checkIns = CoroutineContext::get(self::CHECK_INS_CONTEXT_KEY, []);
        $checkIn = $checkIns[$cacheKey] ?? null;

        // This should never happen (because we should always start before we finish), but better safe than sorry
        if ($checkIn === null) {
            return;
        }

        // We don't need to keep the checkIn stored since we finished executing the command
        unset($checkIns[$cacheKey]);
        CoroutineContext::set(self::CHECK_INS_CONTEXT_KEY, $checkIns);

        $this->sendFinalCheckIn($checkIn, $status);
    }

    /**
     * Send the error check-in for every check-in the task's coroutine started and has not finished.
     *
     * Each task runs in its own coroutine, so the stored check-ins belong to the failed task.
     */
    private function failUnfinishedCheckIns(): void
    {
        if (! $this->shouldHandleCheckIn) {
            return;
        }

        $checkIns = CoroutineContext::get(self::CHECK_INS_CONTEXT_KEY, []);

        if ($checkIns === []) {
            return;
        }

        CoroutineContext::forget(self::CHECK_INS_CONTEXT_KEY);

        foreach ($checkIns as $checkIn) {
            $this->sendFinalCheckIn($checkIn, CheckInStatus::error());
        }
    }

    /**
     * Send a started check-in's terminal status.
     */
    private function sendFinalCheckIn(CheckIn $checkIn, CheckInStatus $status): void
    {
        // The start check-in may still be waiting for asynchronous delivery, so it must not change
        $checkIn = clone $checkIn;
        $checkIn->setStatus($status);

        $this->sendCheckIn($checkIn);
    }

    /**
     * Send the check-in to Sentry.
     */
    private function sendCheckIn(CheckIn $checkIn): void
    {
        $event = SentryEvent::createCheckIn();
        $event->setCheckIn($checkIn);

        SentrySdk::getCurrentHub()->captureEvent($event);
    }

    /**
     * Create a check-in for the monitor with the client's release and environment.
     */
    private function createCheckIn(string $slug, CheckInStatus $status): CheckIn
    {
        $options = SentrySdk::getCurrentHub()->getClient()->getOptions();

        return new CheckIn(
            Str::limit($slug, 128, ''),
            $status,
            null,
            $options->getRelease(),
            $options->getEnvironment()
        );
    }

    /**
     * Build the key that identifies the scheduled event's check-in.
     */
    private function buildCacheKey(string $mutex, string $slug): string
    {
        // We use the mutex name as part of the cache key to avoid collisions between the same commands with the same schedule but with different slugs
        return 'sentry:checkIn:' . hash('xxh128', "{$mutex}:{$slug}");
    }

    /**
     * Generate a monitor slug for the scheduled event.
     */
    private function makeSlugForScheduled(SchedulingEvent $scheduled): string
    {
        if (empty($scheduled->command)) {
            if (! empty($scheduled->description) && class_exists($scheduled->description)) {
                $generatedSlug = Str::slug(
                    // We reverse the class name to have the class name at the start of the slug instead of at the end (and possibly cut off)
                    implode('_', array_reverse(explode('\\', $scheduled->description)))
                );
            } else {
                $generatedSlug = Str::slug($scheduled->description);
            }
        } else {
            $generatedSlug = Str::slug(
                str_replace(
                    // `:` is commonly used in the command name, so we replace it with `-` to avoid it being stripped out by the slug function
                    ':',
                    '-',
                    trim(
                        // Commands built with `formatCommandString()` start with the PHP binary, which isn't relevant to the slug.
                        // Upstream's Str::after() would also remove the start of a command that passes the binary as an argument.
                        Str::chopStart($scheduled->command, ConsoleApplication::phpBinary())
                    )
                )
            );
        }

        return "scheduled_{$generatedSlug}";
    }

    /**
     * Get the Artisan command name of the scheduled event, if it runs a command.
     */
    private function getCommandNameForScheduled(SchedulingEvent $scheduled): ?string
    {
        if (empty($scheduled->command)) {
            return null;
        }

        // Commands built with `formatCommandString()` start with the PHP and Artisan binaries, which aren't part of the name.
        // Upstream's Str::after() would also remove the start of a command that passes them as arguments.
        return trim(
            Str::chopStart($scheduled->command, ConsoleApplication::phpBinary() . ' ' . ConsoleApplication::artisanBinary())
        );
    }
}
