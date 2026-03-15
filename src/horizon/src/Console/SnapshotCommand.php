<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Lock;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:snapshot')]
class SnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:snapshot';

    /**
     * The console command description.
     */
    protected string $description = 'Store a snapshot of the queue metrics';

    /**
     * Execute the console command.
     */
    public function handle(Lock $lock, MetricsRepository $metrics): void
    {
        if ($lock->get('metrics:snapshot', config('horizon.metrics.snapshot_lock', 300))) {
            $metrics->snapshot();

            $this->components->info('Metrics snapshot stored successfully.');
        }
    }
}
