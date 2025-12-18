<?php

declare(strict_types=1);

namespace Hypervel\Horizon\MasterSupervisorCommands;

use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Horizon\SupervisorProcess;
use Symfony\Component\Process\Process;

class AddSupervisor
{
    /**
     * Process the command.
     */
    public function process(MasterSupervisor $master, array $options): void
    {
        $options = SupervisorOptions::fromArray($options);

        $master->supervisors[] = new SupervisorProcess(
            $options,
            $this->createProcess($master, $options),
            function ($type, $line) use ($master) {
                $master->output($type, $line);
            }
        );
    }

    /**
     * Create the Symfony process instance.
     */
    protected function createProcess(MasterSupervisor $master, SupervisorOptions $options): Process
    {
        $command = $options->toSupervisorCommand();

        return Process::fromShellCommandline($command, $options->directory ?? base_path())
            ->setTimeout(null)
            ->disableOutput();
    }
}
