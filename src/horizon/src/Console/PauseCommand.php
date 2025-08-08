<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;

class PauseCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:pause';

    /**
     * The console command description.
     */
    protected string $description = 'Pause the master supervisor';

    /**
     * Execute the console command.
     *
     * @param  \Hypervel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $masters = collect($masters->all())->filter(function ($master) {
            return Str::startsWith($master->name, MasterSupervisor::basename());
        })->all();

        collect(Arr::pluck($masters, 'pid'))
            ->whenNotEmpty(fn () => $this->components->info('Sending USR2 signal to processes.'))
            ->whenEmpty(fn () => $this->components->info('No processes to pause.'))
            ->each(function ($processId) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, &$result) {
                    return $result = posix_kill($processId, SIGUSR2);
                });

                if (! $result) {
                    $this->components->error("Failed to kill process: {$processId} (".posix_strerror(posix_get_last_error()).')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
