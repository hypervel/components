<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\ProvisioningPlan;

class TimeoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:timeout {environment=production : The environment name}';

    /**
     * The console command description.
     */
    protected string $description = 'Get the maximum timeout for the given environment';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     */
    protected bool $hidden = true;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $plan = ProvisioningPlan::get(MasterSupervisor::name())->plan;

        $environment = $this->argument('environment');

        $timeout = collect($plan[$this->argument('environment')] ?? [])->max('timeout') ?? 60;

        $this->components->info('Maximum timeout for '.$environment.' environment: '.$timeout.' seconds.');
    }
}
