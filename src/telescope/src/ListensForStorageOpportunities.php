<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Hypervel\Console\Events\AfterExecute as AfterExecuteCommand;
use Hypervel\Console\Events\BeforeHandle as BeforeHandleCommand;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Telescope\Contracts\EntriesRepository;

trait ListensForStorageOpportunities
{
    public const string PROCESSING_JOBS_CONTEXT_KEY = '__telescope.processing_jobs';

    public const string COMMAND_DEPTH_CONTEXT_KEY = '__telescope.command_depth';

    /**
     * The callback that determines if Telescope should start recording.
     */
    protected static ?Closure $shouldListenCallback = null;

    /**
     * Register listeners that store the recorded Telescope entries.
     *
     * Boot-only. Registers worker-lifetime event listeners; runtime use would
     * accumulate duplicate listeners for every subsequent request/job.
     */
    public static function listenForStorageOpportunities(Container $app): void
    {
        static::recordEntriesForRequests($app);
        static::manageRecordingStateForCommands($app);
        static::storeEntriesAfterWorkerLoop($app);
    }

    /**
     * Set the callback that determines if Telescope should start recording.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and is checked before every Telescope recording opportunity.
     */
    public static function shouldListenUsing(?Closure $callback): void
    {
        static::$shouldListenCallback = $callback;
    }

    /**
     * Determine if Telescope should start recording.
     */
    public static function shouldListen(): bool
    {
        if (is_null(static::$shouldListenCallback)) {
            return true;
        }

        return (bool) (static::$shouldListenCallback)();
    }

    /**
     * Record the entries in queue before the request termination.
     *
     * Boot-only. Registers a worker-lifetime request listener; runtime use
     * would accumulate duplicate listeners.
     */
    public static function recordEntriesForRequests(Container $app): void
    {
        $app->make(Dispatcher::class)
            ->listen(RequestReceived::class, function ($event) use ($app) {
                if (static::shouldListen()
                    && static::requestIsToApprovedUri($app->make(Request::class))
                ) {
                    static::startRecording();
                }
            });
    }

    /**
     * Start recording for approved console commands and scheduled tasks.
     *
     * Boot-only. Registers worker-lifetime command and scheduled-task listeners;
     * runtime use would accumulate duplicate listeners.
     */
    public static function manageRecordingStateForCommands(Container $app): void
    {
        $events = $app->make(Dispatcher::class);

        $events->listen(BeforeHandleCommand::class, function (BeforeHandleCommand $event): void {
            // The long-lived scheduler records only inside each finite task coroutine.
            if ($event->command->getName() === 'schedule:run') {
                return;
            }

            if (! static::commandIsApproved($event->command->getName())) {
                return;
            }

            $depth = (int) CoroutineContext::get(static::COMMAND_DEPTH_CONTEXT_KEY, 0);

            if ($depth === 0 && ! static::shouldListen()) {
                return;
            }

            CoroutineContext::set(static::COMMAND_DEPTH_CONTEXT_KEY, $depth + 1);

            if ($depth === 0) {
                static::startRecording();
            }
        });

        // TelescopeServiceProvider::boot() registers watchers first, so the outer
        // AfterExecute entry reaches the queue before it is stored.
        $events->listen(AfterExecuteCommand::class, function (AfterExecuteCommand $event) use ($app): void {
            if ($event->command->getName() === 'schedule:run'
                || ! static::commandIsApproved($event->command->getName())
            ) {
                return;
            }

            $depth = (int) CoroutineContext::get(static::COMMAND_DEPTH_CONTEXT_KEY, 0);

            if ($depth === 0) {
                return;
            }

            CoroutineContext::set(static::COMMAND_DEPTH_CONTEXT_KEY, --$depth);

            if ($depth === 0) {
                static::store($app->make(EntriesRepository::class));
                static::stopRecording();
            }
        });

        $events->listen(ScheduledTaskStarting::class, function (): void {
            if (static::shouldListen() && static::commandIsApproved('schedule:run')) {
                static::startRecording();
            }
        });
    }

    /**
     * Get the current processing jobs.
     */
    protected static function getProcessingJobs(): array
    {
        return CoroutineContext::get(static::PROCESSING_JOBS_CONTEXT_KEY, []);
    }

    /**
     * Add a processing job to the stack.
     */
    protected static function addProcessingJob(): array
    {
        return CoroutineContext::override(static::PROCESSING_JOBS_CONTEXT_KEY, function ($jobs) {
            $jobs = $jobs ?? [];
            $jobs[] = true;

            return $jobs;
        });
    }

    /**
     * Pop the last processing job from the stack.
     */
    protected static function popProcessingJob(): array
    {
        return CoroutineContext::override(static::PROCESSING_JOBS_CONTEXT_KEY, function ($jobs) {
            $jobs = $jobs ?? [];
            array_pop($jobs);

            return $jobs;
        });
    }

    /**
     * Store entries after the queue worker loops.
     */
    protected static function storeEntriesAfterWorkerLoop(Container $app): void
    {
        $event = $app->make(Dispatcher::class);
        $event->listen(JobProcessing::class, function ($event) {
            if (static::shouldListen() && $event->connectionName !== 'sync') {
                static::startRecording();
                static::addProcessingJob();
            }
        });

        $event->listen(JobProcessed::class, function ($event) use ($app) {
            if (! static::shouldListen()) {
                return;
            }
            static::storeIfDoneProcessingJob($event, $app);
        });

        $event->listen(JobFailed::class, function ($event) use ($app) {
            if (! static::shouldListen()) {
                return;
            }
            static::storeIfDoneProcessingJob($event, $app);
        });

        $event->listen(JobExceptionOccurred::class, function () {
            if (! static::shouldListen()) {
                return;
            }
            static::popProcessingJob();
        });
    }

    /**
     * Store the recorded entries if totally done processing the current job.
     */
    protected static function storeIfDoneProcessingJob(JobFailed|JobProcessed $event, Container $app): void
    {
        static::popProcessingJob();

        if (empty(static::getProcessingJobs()) && $event->connectionName !== 'sync') {
            static::store($app->make(EntriesRepository::class));
            static::stopRecording();
        }
    }
}
