<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:status';

    /**
     * The console command description.
     */
    protected string $description = 'Get the current status of Horizon';

    /**
     * Execute the console command.
     */
    public function handle(MasterSupervisorRepository $masterSupervisorRepository): int
    {
        if (! $masters = $masterSupervisorRepository->all()) {
            $this->components->error('Horizon is inactive.');

            return 2;
        }

        if (collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        })) {
            $this->components->warn('Horizon is paused.');

            return 1;
        }

        $this->components->info('Horizon is running.');

        return 0;
    }
}
