<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hyperf\Command\Command;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use Symfony\Component\Console\Input\InputArgument;

class PruneDbExpiredCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command name.
     */
    protected ?string $name = 'cache:prune-db-expired';

    /**
     * The console command description.
     */
    protected string $description = 'Prune expired cache entries from the database cache';

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        $store = $this->argument('store');

        $cache = $this->app->get(CacheManager::class)->store($store);

        if (! $cache->getStore() instanceof DatabaseStore) {
            $this->error('Pruning expired entries is only necessary when using database cache. To specify a store, use the --store option.');

            return 1;
        }

        $deleted = $cache->getStore()->pruneExpired();

        $this->info("Successfully pruned {$deleted} expired cache entries.");

        return 0;
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['store', InputArgument::OPTIONAL, 'The name of the store you would like to prune'],
        ];
    }
}
