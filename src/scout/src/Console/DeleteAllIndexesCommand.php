<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Exception;
use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Scout\EngineManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete all search indexes.
 */
#[AsCommand(name: 'scout:delete-all-indexes')]
class DeleteAllIndexesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:delete-all-indexes
        {--force : Delete all indexes even when scout.prefix is empty}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete all indexes';

    /**
     * Execute the console command.
     */
    public function handle(EngineManager $manager, Repository $config): int
    {
        // Gate safety first, before resolving the engine. If prefix is empty
        // and --force isn't set, we refuse without ever instantiating the
        // driver's underlying client.
        $prefix = $config->get('scout.prefix', '');
        $force = (bool) $this->option('force');

        if ($prefix === '' && ! $force) {
            $this->error(
                'Cannot safely delete all indexes: scout.prefix is not set. '
                . 'Without a prefix, every index on the search instance would be removed, '
                . 'including indexes belonging to other applications sharing it. '
                . 'Set scout.prefix to scope deletion, or rerun with --force to delete unscoped.'
            );

            return self::FAILURE;
        }

        $engine = $manager->engine();

        if (! method_exists($engine, 'deleteAllIndexes')) {
            $driver = $manager->getDefaultDriver();

            $this->error("The [{$driver}] engine does not support deleting all indexes.");

            return self::FAILURE;
        }

        try {
            $engine->deleteAllIndexes($prefix !== '' ? $prefix : null);

            $this->info('All indexes deleted successfully.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
