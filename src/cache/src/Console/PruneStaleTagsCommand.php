<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'cache:prune-stale-tags')]
class PruneStaleTagsCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'cache:prune-stale-tags';

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

            return 0;
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

        return 0;
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['store', InputArgument::OPTIONAL, 'The name of the store you would like to prune tags from'],
        ];
    }
}
