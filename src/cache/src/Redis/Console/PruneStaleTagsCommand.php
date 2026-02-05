<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console;

use Hyperf\Command\Command;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use Symfony\Component\Console\Input\InputArgument;

class PruneStaleTagsCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command name.
     */
    protected ?string $name = 'cache:prune-redis-stale-tags';

    /**
     * The console command description.
     */
    protected string $description = 'Prune stale cache tags from the cache (Redis only)';

    /**
     * Execute the console command.
     */
    public function handle(): ?int
    {
        $storeName = $this->argument('store') ?? 'redis';

        $repository = $this->app->get(CacheContract::class)->store($storeName);
        $store = $repository->getStore();

        if (! $store instanceof RedisStore) {
            $this->error("The cache store '{$storeName}' is not using the Redis driver.");
            $this->error('This command only works with Redis cache stores.');

            return 1;
        }

        $tagMode = $store->getTagMode();
        $this->info("Pruning stale tags from '{$storeName}' store ({$tagMode->value} mode)...");
        $this->newLine();

        if ($tagMode->isAnyMode()) {
            $stats = $store->anyTagOps()->prune()->execute();
            $this->displayAnyModeStats($stats);
        } else {
            $stats = $store->allTagOps()->prune()->execute();
            $this->displayAllModeStats($stats);
        }

        $this->newLine();
        $this->info('Stale cache tags pruned successfully.');

        return 0;
    }

    /**
     * Display stats for all mode pruning.
     *
     * @param array{tags_scanned: int, stale_entries_removed: int, entries_checked: int, orphans_removed: int, empty_sets_deleted: int} $stats
     */
    protected function displayAllModeStats(array $stats): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Tags scanned', number_format($stats['tags_scanned'])],
                ['Stale entries removed (TTL expired)', number_format($stats['stale_entries_removed'])],
                ['Entries checked for orphans', number_format($stats['entries_checked'])],
                ['Orphaned entries removed', number_format($stats['orphans_removed'])],
                ['Empty tag sets deleted', number_format($stats['empty_sets_deleted'])],
            ]
        );
    }

    /**
     * Display stats for any mode pruning.
     *
     * @param array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int} $stats
     */
    protected function displayAnyModeStats(array $stats): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Tag hashes scanned', number_format($stats['hashes_scanned'])],
                ['Fields checked', number_format($stats['fields_checked'])],
                ['Orphaned fields removed', number_format($stats['orphans_removed'])],
                ['Empty hashes deleted', number_format($stats['empty_hashes_deleted'])],
                ['Expired tags removed from registry', number_format($stats['expired_tags_removed'])],
            ]
        );
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['store', InputArgument::OPTIONAL, 'The name of the store you would like to prune tags from', 'redis'],
        ];
    }
}
