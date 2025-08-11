<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;

class ListCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:list';

    /**
     * The console command description.
     */
    protected string $description = 'List all of the deployed machines';

    /**
     * Execute the console command.
     */
    public function handle(MasterSupervisorRepository $masters): void
    {
        $masters = $masters->all();

        if (empty($masters)) {
            $this->components->info('No machines are running.');
            return;
        }

        $this->output->writeln('');

        $this->table([
            'Name', 'PID', 'Supervisors', 'Status',
        ], collect($masters)->map(function ($master) {
            return [
                $master->name,
                $master->pid,
                $master->supervisors ? collect($master->supervisors)->map(function ($supervisor) {
                    return explode(':', $supervisor, 2)[1];
                })->implode(', ') : 'None',
                $master->status,
            ];
        })->all());

        $this->output->writeln('');
    }
}
