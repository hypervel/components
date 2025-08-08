<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Str;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;

class PauseSupervisorCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:pause-supervisor
                            {name : The name of the supervisor to pause}';

    /**
     * The console command description.
     */
    protected string $description = 'Pause a supervisor';

    /**
     * Execute the console command.
     */
    public function handle(SupervisorRepository $supervisors): int
    {
        $processId = optional(collect($supervisors->all())->first(function ($supervisor) {
            return Str::startsWith($supervisor->name, MasterSupervisor::basename())
                    && Str::endsWith($supervisor->name, $this->argument('name'));
        }))->pid;

        if (is_null($processId)) {
            $this->components->error('Failed to find a supervisor with this name');

            return 1;
        }

        $this->components->info("Sending USR2 signal to process: {$processId}");

        if (! posix_kill($processId, SIGUSR2)) {
            $this->components->error("Failed to send USR2 signal to process: {$processId} (".posix_strerror(posix_get_last_error()).')');
        }
    }
}
