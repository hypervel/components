<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Foundation\Console\Command;
use Hypervel\Support\Carbon;
use Hypervel\Telescope\Contracts\PrunableRepository;

class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:prune {--hours=24 : The number of hours to retain Telescope data} {--keep-exceptions : Retain exception data}';

    /**
     * The console command description.
     */
    protected string $description = 'Prune stale entries from the Telescope database';

    /**
     * Execute the console command.
     */
    public function handle(PrunableRepository $repository)
    {
        $this->info($repository->prune(Carbon::now()->subHours($this->option('hours')), $this->option('keep-exceptions')) . ' entries pruned.');
    }
}
