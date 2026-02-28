<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'cache:prune-db-expired')]
class PruneDbExpiredCommand extends Command
{
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
        $cache = $this->app->make(CacheManager::class)->store($store);

        if (! $cache->getStore() instanceof DatabaseStore) {
            if (is_null($store)) {
                $this->error('The default cache store is not using the database driver.');
                $this->line('');
                $this->line('To prune a specific database cache store, use:');
                $this->line('  <info>artisan cache:prune-db-expired <store-name></info>');
                $this->line('');
                $this->line('Example: <info>artisan cache:prune-db-expired database</info>');
            } else {
                $this->error("The cache store [{$store}] is not using the database driver.");
            }

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
