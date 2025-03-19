<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hypervel\Bus\Contracts\BatchRepository;
use Hypervel\Bus\Contracts\PrunableBatchRepository;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Support\Carbon;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class PruneBatchesCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command signature.
     */
    protected ?string $signature = 'queue:prune-batches
                {--hours=24 : The number of hours to retain batch data}
                {--unfinished= : The number of hours to retain unfinished batch data }
                {--cancelled= : The number of hours to retain cancelled batch data }';

    /**
     * The console command description.
     */
    protected string $description = 'Prune stale entries from the batches database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $repository = $this->app->get(BatchRepository::class);

        $count = 0;

        if ($repository instanceof PrunableBatchRepository) {
            $count = $repository->prune(Carbon::now()->subHours($this->option('hours')));
        }

        $this->info("{$count} entries deleted.");

        if ($this->option('unfinished') !== null) {
            $count = 0;

            if ($repository instanceof DatabaseBatchRepository) {
                $count = $repository->pruneUnfinished(Carbon::now()->subHours($this->option('unfinished')));
            }

            $this->info("{$count} unfinished entries deleted.");
        }

        if ($this->option('cancelled') !== null) {
            $count = 0;

            if ($repository instanceof DatabaseBatchRepository) {
                $count = $repository->pruneCancelled(Carbon::now()->subHours($this->option('cancelled')));
            }

            $this->info("{$count} cancelled entries deleted.");
        }
    }
}
