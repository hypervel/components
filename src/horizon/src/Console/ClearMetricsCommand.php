<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\MetricsRepository;

class ClearMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:clear-metrics';

    /**
     * The console command description.
     */
    protected string $description = 'Delete metrics for all jobs and queues';

    /**
     * Execute the console command.
     */
    public function handle(MetricsRepository $metrics): void
    {
        $metrics->clear();

        $this->components->info('Metrics cleared successfully.');
    }
}
