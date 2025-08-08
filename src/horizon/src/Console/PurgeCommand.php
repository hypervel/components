<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Str;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\ProcessRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\ProcessInspector;

class PurgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:purge
                            {--signal=SIGTERM : The signal to send to the rogue processes}';

    /**
     * The console command description.
     */
    protected string $description = 'Terminate any rogue Horizon processes';

    /**
     * @var \Hypervel\Horizon\Contracts\SupervisorRepository
     */
    private $supervisors;

    /**
     * @var \Hypervel\Horizon\Contracts\ProcessRepository
     */
    private $processes;

    /**
     * @var \Hypervel\Horizon\ProcessInspector
     */
    private $inspector;

    /**
     * Create a new command instance.
     *
     * @param  \Hypervel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Hypervel\Horizon\Contracts\ProcessRepository  $processes
     * @param  \Hypervel\Horizon\ProcessInspector  $inspector
     * @return void
     */
    public function __construct(
        SupervisorRepository $supervisors,
        ProcessRepository $processes,
        ProcessInspector $inspector
    ) {
        parent::__construct();

        $this->supervisors = $supervisors;
        $this->processes = $processes;
        $this->inspector = $inspector;
    }

    /**
     * Execute the console command.
     *
     * @param  \Hypervel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $signal = is_numeric($signal = $this->option('signal'))
                        ? $signal
                        : constant($signal);

        foreach ($masters->names() as $master) {
            if (Str::startsWith($master, MasterSupervisor::basename())) {
                $this->purge($master, $signal);
            }
        }
    }

    /**
     * Purge any orphan processes.
     *
     * @param  string  $master
     * @param  int  $signal
     * @return void
     */
    public function purge($master, $signal = SIGTERM)
    {
        $this->recordOrphans($master, $signal);

        $expired = $this->processes->orphanedFor(
            $master, $this->supervisors->longestActiveTimeout()
        );

        collect($expired)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to expired processes of ['.$master.']'))
            ->each(function ($processId) use ($master, $signal) {
                $this->components->task("Process: $processId", function () use ($processId, $signal) {
                    exec("kill -s {$signal} {$processId}");
                });

                $this->processes->forgetOrphans($master, [$processId]);
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }

    /**
     * Record the orphaned Horizon processes.
     *
     * @param  string  $master
     * @param  int  $signal
     * @return void
     */
    protected function recordOrphans($master, $signal)
    {
        $this->processes->orphaned(
            $master, $orphans = $this->inspector->orphaned()
        );

        collect($orphans)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to orphaned processes of ['.$master.']'))
            ->each(function ($processId) use ($signal) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, $signal, &$result) {
                    return $result = posix_kill($processId, $signal);
                });

                if (! $result) {
                    $this->components->error("Failed to kill orphan process: {$processId} (".posix_strerror(posix_get_last_error()).')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
