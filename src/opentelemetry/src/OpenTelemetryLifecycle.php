<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\OpenTelemetry\Support\ExportScheduler;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Throwable;

class OpenTelemetryLifecycle
{
    use LogsMessagesTrait;

    protected bool $ownsCliLifecycle = false;

    protected bool $terminatingListenerRegistered = false;

    protected int $activeCliCommandsInCoroutine = 0;

    /**
     * Create an OpenTelemetry lifecycle coordinator.
     */
    public function __construct(
        protected OpenTelemetryManager $manager,
        protected ExportScheduler $scheduler,
        protected Repository $config,
        protected Dispatcher $events,
    ) {
    }

    /**
     * Bind an event or task worker and start periodic export.
     */
    public function startWorker(AfterWorkerStart $event): void
    {
        $identity = $event->server->taskworker
            ? ProcessIdentity::taskWorker($event->workerId)
            : ProcessIdentity::eventWorker($event->workerId);

        try {
            $this->manager->bind($identity);
        } finally {
            if ($this->manager->isBound()) {
                $this->scheduler->start();
            }
        }
    }

    /**
     * Bind the standalone CLI lifecycle.
     */
    public function startCli(): void
    {
        if ($this->manager->isBound()) {
            return;
        }

        try {
            $this->manager->bind(ProcessIdentity::cli());
        } finally {
            if ($this->manager->isBound()) {
                $this->ownsCliLifecycle = true;
                $this->registerTerminatingListener();
            }
        }
    }

    /**
     * Mark a selected CLI command as active.
     */
    public function beginCliCommand(): void
    {
        if (! $this->ownsCliLifecycle || ! Coroutine::inCoroutine()) {
            return;
        }

        if (++$this->activeCliCommandsInCoroutine === 1) {
            $this->scheduler->start();
        }
    }

    /**
     * Mark a selected CLI command as complete.
     */
    public function endCliCommand(): void
    {
        if (! $this->ownsCliLifecycle || ! Coroutine::inCoroutine()) {
            return;
        }

        if ($this->activeCliCommandsInCoroutine === 0) {
            return;
        }

        if (--$this->activeCliCommandsInCoroutine === 0) {
            $this->scheduler->stop();
        }
    }

    /**
     * Bind a custom server process unless its class is excluded.
     */
    public function startProcess(BeforeProcessHandle $event): void
    {
        if (in_array(
            $event->process::class,
            $this->config->array('opentelemetry.server_processes.except'),
            true,
        )) {
            return;
        }

        $this->manager->bind(ProcessIdentity::serverProcess(
            $event->process::class,
            $event->process->name,
            $event->index,
        ));

        if ($event->process->enableCoroutine) {
            $this->scheduler->start();
        }
    }

    /**
     * Close a custom server-process lifecycle.
     */
    public function finishProcess(AfterProcessHandle $event): void
    {
        if (! $this->manager->isBound()) {
            return;
        }

        $this->close();
    }

    /**
     * Close the standalone CLI lifecycle.
     */
    public function terminate(Terminating $event): void
    {
        if (! $this->ownsCliLifecycle) {
            return;
        }

        $this->activeCliCommandsInCoroutine = 0;
        $this->ownsCliLifecycle = false;
        $this->close();
    }

    /**
     * Register the CLI shutdown listener once after the first successful bind.
     */
    protected function registerTerminatingListener(): void
    {
        if ($this->terminatingListenerRegistered) {
            return;
        }

        $this->terminatingListenerRegistered = true;
        $this->events->listen(Terminating::class, function (Terminating $event): void {
            $this->terminate($event);
        });
    }

    /**
     * Close providers while isolating automatic telemetry failures.
     */
    protected function close(): void
    {
        try {
            if (! $this->scheduler->shutdown()) {
                self::logError('OpenTelemetry provider shutdown did not complete successfully.');
            }
        } catch (Throwable $exception) {
            self::logError('OpenTelemetry provider shutdown failed.', ['exception' => $exception]);
        }
    }
}
