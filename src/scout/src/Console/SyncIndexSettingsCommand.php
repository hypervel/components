<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Scout;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Sync configured index settings with the search engine.
 */
#[AsCommand(name: 'scout:sync-index-settings')]
class SyncIndexSettingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:sync-index-settings
        {--driver= : The name of the search engine driver (Defaults to configuration value: `scout.driver`)}';

    /**
     * The console command description.
     */
    protected string $description = 'Sync your configured index settings with your search engine';

    /**
     * Execute the console command.
     */
    public function handle(EngineManager $manager, Repository $config): int
    {
        $driver = $this->option('driver');
        $driver = $driver === null || $driver === '' ? $config->string('scout.driver') : $driver;

        $engine = $manager->engine($driver);

        if (! $engine instanceof UpdatesIndexSettings) {
            $this->error("The \"{$driver}\" engine does not support updating index settings.");

            return self::FAILURE;
        }

        $indexes = $config->array("scout.{$driver}.index-settings", []);

        if (count($indexes) === 0) {
            $this->info("No index settings found for the \"{$driver}\" engine.");

            return self::SUCCESS;
        }

        foreach ($indexes as $name => $settings) {
            if (! is_array($settings)) {
                $name = $settings;
                $settings = [];
            }

            /** @var null|Model $model */
            $model = null;
            if (class_exists($name)) {
                $model = new $name;
            }

            if ($model !== null
                && $config->boolean('scout.soft_delete', false)
                && in_array(SoftDeletes::class, class_uses_recursive($model))) {
                $settings = $engine->configureSoftDeleteFilter($settings);
            }

            $indexName = $this->indexName($name, $config);
            $settings = Scout::prepareIndexSettings($settings, $model, $engine, $indexName);
            $engine->updateIndexSettings($indexName, $settings);

            $this->info("Settings for the [{$indexName}] index synced successfully.");
        }

        return self::SUCCESS;
    }

    /**
     * Get the fully-qualified index name for the given index.
     */
    protected function indexName(string $name, Repository $config): string
    {
        if (class_exists($name)) {
            return (new $name)->indexableAs();
        }

        $prefix = $config->string('scout.prefix', '');

        return ! Str::startsWith($name, $prefix) ? $prefix . $name : $name;
    }
}
