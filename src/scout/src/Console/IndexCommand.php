<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\Engine;
use Hypervel\Scout\EngineManager;
use Hypervel\Support\Str;

/**
 * Create a search index.
 */
class IndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'scout:index
        {name : The name of the index}
        {--k|key= : The name of the primary key}';

    /**
     * The console command description.
     */
    protected string $description = 'Create an index';

    /**
     * Execute the console command.
     */
    public function handle(EngineManager $manager, ConfigInterface $config): int
    {
        $engine = $manager->engine();

        $options = [];

        if ($this->option('key')) {
            $options = ['primaryKey' => $this->option('key')];
        }

        $model = null;
        $modelName = (string) $this->argument('name');

        if (class_exists($modelName)) {
            $model = new $modelName();
        }

        $name = $this->indexName($modelName, $config);

        $this->createIndex($engine, $name, $options);

        if ($engine instanceof UpdatesIndexSettings) {
            $driver = $config->get('scout.driver');

            $class = $model !== null ? get_class($model) : null;

            $settings = $config->get("scout.{$driver}.index-settings.{$name}")
                ?? ($class !== null ? $config->get("scout.{$driver}.index-settings.{$class}") : null)
                ?? [];

            if ($model !== null
                && $config->get('scout.soft_delete', false)
                && in_array(SoftDeletes::class, class_uses_recursive($model))) {
                $settings = $engine->configureSoftDeleteFilter($settings);
            }

            if ($settings) {
                $engine->updateIndexSettings($name, $settings);
            }
        }

        $this->info("Synchronised index [\"{$name}\"] successfully.");

        return self::SUCCESS;
    }

    /**
     * Create a search index.
     *
     * @param array<string, mixed> $options
     */
    protected function createIndex(Engine $engine, string $name, array $options): void
    {
        $engine->createIndex($name, $options);
    }

    /**
     * Get the fully-qualified index name for the given index.
     */
    protected function indexName(string $name, ConfigInterface $config): string
    {
        if (class_exists($name)) {
            return (new $name())->indexableAs();
        }

        $prefix = $config->get('scout.prefix', '');

        return ! Str::startsWith($name, $prefix) ? $prefix . $name : $name;
    }
}
