<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:supervisors')]
class SupervisorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:supervisors';

    /**
     * The console command description.
     */
    protected string $description = 'List all of the supervisors';

    /**
     * Execute the console command.
     */
    public function handle(SupervisorRepository $supervisors): void
    {
        $supervisors = $supervisors->all();

        if (empty($supervisors)) {
            $this->components->info('No supervisors are running.');
            return;
        }

        $this->output->writeln('');

        $this->table([
            'Name', 'PID', 'Status', 'Workers', 'Balancing',
        ], collect($supervisors)->map(function ($supervisor) {
            return [
                $supervisor->name,
                $supervisor->pid,
                $supervisor->status,
                collect($supervisor->processes)->map(function ($count, $queue) {
                    return $queue . ' (' . $count . ')';
                })->implode(', '),
                $supervisor->options['balance'],
            ];
        })->all());

        $this->output->writeln('');
    }
}
