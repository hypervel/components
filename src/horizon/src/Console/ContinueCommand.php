<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;

class ContinueCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:continue';

    /**
     * The console command description.
     */
    protected string $description = 'Instruct the master supervisor to continue processing jobs';

    /**
     * Execute the console command.
     */
    public function handle(MasterSupervisorRepository $masters): void
    {
        $masters = collect($masters->all())->filter(function ($master) {
            return Str::startsWith($master->name, MasterSupervisor::basename());
        })->all();

        collect(Arr::pluck($masters, 'pid'))
            ->whenNotEmpty(fn () => $this->components->info('Sending CONT signal to processes.'))
            ->whenEmpty(fn () => $this->components->info('No processes to continue.'))
            ->each(function ($processId) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, &$result) {
                    return $result = posix_kill($processId, SIGCONT);
                });

                if (! $result) {
                    $this->components->error("Failed to kill process: {$processId} (".posix_strerror(posix_get_last_error()).')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
