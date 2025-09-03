<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Hypervel\Horizon\SupervisorCommands\Terminate;
use Override;
use Symfony\Component\Process\Process;

class SupervisorProcess extends WorkerProcess
{
    /**
     * The name of the supervisor.
     */
    public string $name;

    /**
     * Indicates if the process is "dead".
     */
    public bool $dead = false;

    /**
     * The exit codes on which supervisor should be marked as dead.
     */
    public array $dontRestartOn = [
        0,
        2,
        13, // Indicates duplicate supervisors...
    ];

    /**
     * Create a new supervisor process instance.
     */
    public function __construct(
        public SupervisorOptions $options,
        Process $process,
        ?Closure $output = null
    ) {
        $this->options = $options;
        $this->name = $options->name;

        $this->output = $output ?: function () {
        };

        parent::__construct($process);
    }

    /**
     * Evaluate the current state of the process.
     */
    #[Override]
    public function monitor(): void
    {
        if (! $this->process->isStarted()) {
            $this->restart();
            return;
        }

        // First, we will check to see if the supervisor failed as a duplicate and if
        // it did we will go ahead and mark it as dead. We will do this before the
        // other checks run because we do not care if this is cooling down here.
        if (! $this->process->isRunning()
            && $this->process->getExitCode() === 13
        ) {
            $this->markAsDead();
            return;
        }

        // If the process is running or cooling down from a failure, we don't need to
        // attempt to do anything right now, so we can just bail out of the method
        // here and it will get checked out during the next master monitor loop.
        if ($this->process->isRunning() || $this->coolingDown()) {
            return;
        }

        // Next, we will determine if the exit code is one that means this supervisor
        // should be marked as dead and not be restarted. Typically, this could be
        // an indication that the supervisor was simply purposefully terminated.
        $exitCode = $this->process->getExitCode();

        $this->markAsDead();

        // If the supervisor exited with a status code that we do not restart on then
        // we will not attempt to restart it. Otherwise, we will need to provision
        // it back out based on the latest provisioning information we have now.
        if (in_array($exitCode, $this->dontRestartOn)) {
            return;
        }

        $this->reprovision();
    }

    /**
     * Re-provision this supervisor process based on the provisioning plan.
     */
    protected function reprovision(): void
    {
        if (isset($this->name)) {
            app(SupervisorRepository::class)->forget($this->name);
        }

        app(HorizonCommandQueue::class)->push(
            MasterSupervisor::commandQueue(),
            AddSupervisor::class,
            $this->options->toArray()
        );
    }

    /**
     * Terminate the supervisor with the given status.
     */
    public function terminateWithStatus(int $status): void
    {
        app(HorizonCommandQueue::class)->push(
            $this->options->name,
            Terminate::class,
            ['status' => $status]
        );
    }

    /**
     * Mark the process as "dead".
     */
    protected function markAsDead(): void
    {
        $this->dead = true;
    }
}
