<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Str;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;

class SupervisorStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:supervisor-status
                            {name : The name of the supervisor}';

    /**
     * The console command description.
     */
    protected string $description = 'Show the status for a given supervisor';

    /**
     * Execute the console command.
     */
    public function handle(SupervisorRepository $supervisors): int
    {
        $name = $this->argument('name');

        $supervisorStatus = optional(collect($supervisors->all())->first(function ($supervisor) use ($name) {
            return Str::startsWith($supervisor->name, MasterSupervisor::basename()) &&
                   Str::endsWith($supervisor->name, $name);
        }))->status;

        if (is_null($supervisorStatus)) {
            $this->components->error('Unable to find a supervisor with this name.');

            return 1;
        }

        $this->components->info("{$name} is {$supervisorStatus}");
    }
}
