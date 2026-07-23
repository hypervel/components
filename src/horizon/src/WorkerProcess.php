<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Events\UnableToLaunchProcess;
use Hypervel\Horizon\Events\WorkerProcessRestarting;
use Hypervel\Support\CarbonImmutable;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * @mixin Process
 */
class WorkerProcess
{
    /**
     * Signals handled by a queue worker after its application boots.
     */
    protected const STARTUP_SIGNALS = [
        SIGQUIT,
        SIGTERM,
        SIGINT,
        SIGUSR2,
        SIGCONT,
    ];

    /**
     * The output handler callback.
     */
    public ?Closure $output = null;

    /**
     * The time at which the cooldown period will be over.
     */
    public ?CarbonImmutable $restartAgainAt = null;

    /**
     * Create a new worker process instance.
     *
     * @param Process $process the underlying Symfony process
     */
    public function __construct(
        public Process $process
    ) {
    }

    /**
     * Start the process.
     */
    public function start(Closure $callback): static
    {
        $this->output = $callback;

        $this->cooldown();

        $previousMask = [];

        // Symfony's setIgnoredSignals() also suppresses Process::signal() on
        // SIGCHLD builds. Horizon must still send these control signals, so
        // only the fork-inherited process mask is changed here.
        if (! pcntl_sigprocmask(SIG_BLOCK, static::STARTUP_SIGNALS, $previousMask)) {
            throw new RuntimeException('Unable to block Horizon child startup signals.');
        }

        $exception = null;

        try {
            $this->process->start($callback);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        if (! pcntl_sigprocmask(SIG_SETMASK, $previousMask)) {
            $exception ??= new RuntimeException('Unable to restore the Horizon parent signal mask.');
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $this;
    }

    /**
     * Pause the worker process.
     */
    public function pause(): void
    {
        $this->sendSignal(SIGUSR2);
    }

    /**
     * Instruct the worker process to continue working.
     */
    public function continue(): void
    {
        $this->sendSignal(SIGCONT);
    }

    /**
     * Evaluate the current state of the process.
     */
    public function monitor(): void
    {
        if ($this->process->isRunning() || ($this->coolingDown() && $this->process->getExitCode() !== 0)) {
            return;
        }

        $this->restart();
    }

    /**
     * Restart the process.
     */
    protected function restart(): void
    {
        if ($this->process->isStarted()) {
            /** @var Dispatcher $events */
            $events = app('events');

            if ($events->hasListeners(WorkerProcessRestarting::class)) {
                $events->dispatch(new WorkerProcessRestarting($this));
            }
        }

        $this->start($this->output);
    }

    /**
     * Terminate the underlying process.
     */
    public function terminate(): void
    {
        $this->sendSignal(SIGTERM);
    }

    /**
     * Stop the underlying process.
     */
    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop();
        }
    }

    /**
     * Stop the underlying process immediately.
     */
    public function kill(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0);
        }
    }

    /**
     * Send a POSIX signal to the process.
     */
    protected function sendSignal(int $signal): void
    {
        try {
            $this->process->signal($signal);
        } catch (ExceptionInterface $e) {
            if ($this->process->isRunning()) {
                throw $e;
            }
        }
    }

    /**
     * Begin the cool-down period for the process.
     */
    protected function cooldown(): void
    {
        if ($this->coolingDown()) {
            return;
        }

        if ($this->restartAgainAt) {
            $this->restartAgainAt = ! $this->process->isRunning()
                            ? CarbonImmutable::now()->addMinute()
                            : null;

            if (! $this->process->isRunning()) {
                /** @var Dispatcher $events */
                $events = app('events');

                if ($events->hasListeners(UnableToLaunchProcess::class)) {
                    $events->dispatch(new UnableToLaunchProcess($this));
                }
            }
        } else {
            $this->restartAgainAt = CarbonImmutable::now()->addSecond();
        }
    }

    /**
     * Determine if the process is cooling down from a failed restart.
     */
    public function coolingDown(): bool
    {
        return isset($this->restartAgainAt)
               && CarbonImmutable::now()->lt($this->restartAgainAt);
    }

    /**
     * Set the output handler.
     */
    public function handleOutputUsing(Closure $callback): static
    {
        $this->output = $callback;

        return $this;
    }

    /**
     * Pass on method calls to the underlying process.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->process->{$method}(...$parameters);
    }
}
