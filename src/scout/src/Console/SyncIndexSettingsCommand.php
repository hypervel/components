<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\EngineManager;
use Hypervel\Support\Str;

/**
 * Sync configured index settings with the search engine.
 */
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
        $driver = $this->option('driver') ?: $config->get('scout.driver');

        $engine = $manager->engine($driver);

        if (! $engine instanceof UpdatesIndexSettings) {
            $this->error("The \"{$driver}\" engine does not support updating index settings.");

            return self::FAILURE;
        }

        $indexes = (array) $config->get("scout.{$driver}.index-settings", []);

        if (count($indexes) === 0) {
            $this->info("No index settings found for the \"{$driver}\" engine.");

            return self::SUCCESS;
        }

        foreach ($indexes as $name => $settings) {
            if (! is_array($settings)) {
                $name = $settings;
                $settings = [];
            }

            $model = null;
            if (class_exists($name)) {
                $model = new $name();
            }

            if ($model !== null
                && $config->get('scout.soft_delete', false)
                && in_array(SoftDeletes::class, class_uses_recursive($model))) {
                $settings = $engine->configureSoftDeleteFilter($settings);
            }

            $indexName = $this->indexName($name, $config);
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
            return (new $name())->indexableAs();
        }

        $prefix = $config->get('scout.prefix', '');

        return ! Str::startsWith($name, $prefix) ? $prefix . $name : $name;
    }
}
