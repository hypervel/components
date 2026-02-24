<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Scout\EngineManager;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Delete a search index.
 */
#[AsCommand(name: 'scout:delete-index')]
class DeleteIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:delete-index
        {name : The name of the index}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete an index';

    /**
     * Execute the console command.
     */
    public function handle(EngineManager $manager, Repository $config): int
    {
        $name = $this->indexName((string) $this->argument('name'), $config);

        $manager->engine()->deleteIndex($name);

        $this->info("Index \"{$name}\" deleted.");

        return self::SUCCESS;
    }

    /**
     * Get the fully-qualified index name for the given index.
     */
    protected function indexName(string $name, Repository $config): string
    {
        if (class_exists($name)) {
            return (new $name())->indexableAs();
        }

        $prefix = $config->get('scout.prefix', '');

        return ! Str::startsWith($name, $prefix) ? $prefix . $name : $name;
    }
}
