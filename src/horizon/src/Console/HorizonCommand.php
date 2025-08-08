<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\ProvisioningPlan;

class HorizonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon {--environment= : The environment name}';

    /**
     * The console command description.
     */
    protected string $description = 'Start a master supervisor in the foreground';

    /**
     * Execute the console command.
     *
     * @param  \Hypervel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        if ($masters->find(MasterSupervisor::name())) {
            return $this->components->warn('A master supervisor is already running on this machine.');
        }

        $environment = $this->option('environment') ?? config('horizon.env') ?? config('app.env');

        $master = (new MasterSupervisor($environment))->handleOutputUsing(function ($type, $line) {
            $this->output->write($line);
        });

        ProvisioningPlan::get(MasterSupervisor::name())->deploy($environment);

        $this->components->info('Horizon started successfully.');

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($master) {
            $this->output->writeln('');

            $this->components->info('Shutting down.');

            return $master->terminate();
        });

        $master->monitor();
    }
}
