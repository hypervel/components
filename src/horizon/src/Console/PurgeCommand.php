<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\ProcessRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\ProcessInspector;
use Hypervel\Support\Str;

class PurgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:purge
                            {--signal=SIGTERM : The signal to send to the rogue processes}';

    /**
     * The console command description.
     */
    protected string $description = 'Terminate any rogue Horizon processes';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private SupervisorRepository $supervisors,
        private ProcessRepository $processes,
        private ProcessInspector $inspector
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(MasterSupervisorRepository $masters): void
    {
        $signal = is_numeric($signal = $this->option('signal'))
                        ? $signal
                        : constant($signal);
        $signal = (int) $signal;

        foreach ($masters->names() as $master) {
            if (Str::startsWith($master, MasterSupervisor::basename())) {
                $this->purge($master, $signal);
            }
        }
    }

    /**
     * Purge any orphan processes.
     */
    public function purge(string $master, int $signal = SIGTERM): void
    {
        $this->recordOrphans($master, $signal);

        $expired = $this->processes->orphanedFor(
            $master,
            $this->supervisors->longestActiveTimeout()
        );

        collect($expired)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to expired processes of [' . $master . ']'))
            ->each(function ($processId) use ($master, $signal) {
                $this->components->task("Process: {$processId}", function () use ($processId, $signal) {
                    exec("kill -s {$signal} {$processId}");
                });

                $this->processes->forgetOrphans($master, [$processId]);
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }

    /**
     * Record the orphaned Horizon processes.
     */
    protected function recordOrphans(string $master, int $signal): void
    {
        $this->processes->orphaned(
            $master,
            $orphans = $this->inspector->orphaned()
        );

        collect($orphans)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to orphaned processes of [' . $master . ']'))
            ->each(function ($processId) use ($signal) {
                $result = true;

                $this->components->task("Process: {$processId}", function () use ($processId, $signal, &$result) {
                    return $result = posix_kill((int) $processId, $signal);
                });

                if (! $result) {
                    $this->components->error("Failed to kill orphan process: {$processId} (" . posix_strerror(posix_get_last_error()) . ')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
