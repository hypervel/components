<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Hyperf\Command\Event\AfterExecute as AfterExecuteCommand;
use Hyperf\Command\Event\BeforeHandle as BeforeHandleCommand;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Event\RequestReceived;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

trait ListensForStorageOpportunities
{
    public const PROCESSING_JOBS = 'telescope.processing_jobs';

    /**
     * The callback that determines if Telescope should start recording.
     */
    protected static ?Closure $shouldListenCallback = null;

    /**
     * Register listeners that store the recorded Telescope entries.
     */
    public static function listenForStorageOpportunities(ContainerInterface $app): void
    {
        static::recordEntriesForRequests($app);
        static::manageRecordingStateForCommands($app);
        static::storeEntriesAfterWorkerLoop($app);
    }

    /**
     * Set the callback that determines if Telescope should start recording.
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
     */
    public static function recordEntriesForRequests(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
            ->listen(RequestReceived::class, function ($event) use ($app) {
                if (static::shouldListen()
                    && static::requestIsToApprovedUri($app->get(RequestContract::class))
                ) {
                    static::startRecording();
                }
            });
    }

    /**
     * Manage starting and stopping the recording state for commands.
     */
    public static function manageRecordingStateForCommands(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
            ->listen(BeforeHandleCommand::class, function () {
                if (static::shouldListen()
                    && static::runningApprovedArtisanCommand()
                ) {
                    static::startRecording();
                }
            });
        $app->get(EventDispatcherInterface::class)
            ->listen(AfterExecuteCommand::class, function () use ($app) {
                static::store(
                    $app->get(EntriesRepository::class)
                );
            });
    }

    /**
     * Get the current processing jobs.
     */
    protected static function getProcessingJobs(): array
    {
        return Context::get(static::PROCESSING_JOBS, []);
    }

    /**
     * Add a processing job to the stack.
     */
    protected static function addProcessingJob(): array
    {
        return Context::override(static::PROCESSING_JOBS, function ($jobs) {
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
        return Context::override(static::PROCESSING_JOBS, function ($jobs) {
            $jobs = $jobs ?? [];
            array_pop($jobs);

            return $jobs;
        });
    }

    /**
     * Store entries after the queue worker loops.
     */
    protected static function storeEntriesAfterWorkerLoop(ContainerInterface $app): void
    {
        $event = $app->get(EventDispatcherInterface::class);
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
    protected static function storeIfDoneProcessingJob(JobFailed|JobProcessed $event, ContainerInterface $app): void
    {
        static::popProcessingJob();

        if (empty(static::getProcessingJobs()) && $event->connectionName !== 'sync') {
            static::store($app->get(EntriesRepository::class));
            static::stopRecording();
        }
    }
}
