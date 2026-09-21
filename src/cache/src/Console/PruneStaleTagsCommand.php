<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'cache:prune-stale-tags')]
class PruneStaleTagsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'cache:prune-stale-tags {store? : The name of the store you would like to prune tags from}';

    /**
     * The console command description.
     */
    protected string $description = 'Prune stale cache tags from the cache';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $store = $this->hypervel->make(CacheContract::class)
            ->store($this->argument('store'))
            ->getStore();

        if (! method_exists($store, 'flushStaleTags')) {
            $this->components->info('The selected cache store does not support pruning stale tags.');

            return self::SUCCESS;
        }

        $stats = $store->flushStaleTags();

        if ($stats) {
            $this->table(
                ['Metric', 'Value'],
                collect($stats)->map(fn (int $value, string $key) => [
                    Str::headline($key),
                    number_format($value),
                ])->values()->all()
            );

            $this->newLine();
        }

        $this->components->info('Stale cache tags pruned successfully.');

        return self::SUCCESS;
    }
}
