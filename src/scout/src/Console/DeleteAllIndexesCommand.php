<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Exception;
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
    protected ?string $signature = 'scout:delete-all-indexes';

    /**
     * The console command description.
     */
    protected string $description = 'Delete all indexes';

    /**
     * Execute the console command.
     */
    public function handle(EngineManager $manager): int
    {
        $engine = $manager->engine();

        if (! method_exists($engine, 'deleteAllIndexes')) {
            $driver = $manager->getDefaultDriver();

            $this->error("The [{$driver}] engine does not support deleting all indexes.");

            return self::FAILURE;
        }

        try {
            $engine->deleteAllIndexes();

            $this->info('All indexes deleted successfully.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
