<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Carbon\CarbonImmutable;
use Closure;
use Hypervel\Horizon\Events\UnableToLaunchProcess;
use Hypervel\Horizon\Events\WorkerProcessRestarting;
use Symfony\Component\Process\Exception\ExceptionInterface;

class WorkerProcess
{
    /**
     * The output handler callback.
     */
    public ?Closure $output = null;

    /**
     * The time at which the cooldown period will be over.
     */
    public ?\Carbon\CarbonImmutable $restartAgainAt = null;

    /**
     * Create a new worker process instance.
     *
     * @param \Symfony\Component\Process\Process $process the underlying Symfony process
     */
    public function __construct(
        public \Symfony\Component\Process\Process $process
    ) {
    }

    /**
     * Start the process.
     */
    public function start(Closure $callback): static
    {
        $this->output = $callback;

        $this->cooldown();

        $this->process->start($callback);

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
            event(new WorkerProcessRestarting($this));
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
                event(new UnableToLaunchProcess($this));
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
