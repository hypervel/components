<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Scout;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Create a search index.
 */
#[AsCommand(name: 'scout:index')]
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
    public function handle(EngineManager $manager, Repository $config): int
    {
        $engine = $manager->engine();

        $options = [];

        $key = $this->option('key');

        if ($key !== null && $key !== '') {
            $options = ['primaryKey' => $key];
        }

        /** @var null|Model $model */
        $model = null;
        $modelName = (string) $this->argument('name');

        if (class_exists($modelName)) {
            $model = new $modelName;
        }

        $name = $this->indexName($modelName, $config);

        $this->createIndex($engine, $name, $options);

        if ($engine instanceof UpdatesIndexSettings) {
            $driver = $config->string('scout.driver');

            $settings = $config->get("scout.{$driver}.index-settings.{$modelName}")
                ?? $config->get("scout.{$driver}.index-settings.{$name}")
                ?? [];

            if ($model !== null
                && $config->boolean('scout.soft_delete', false)
                && in_array(SoftDeletes::class, class_uses_recursive($model))) {
                $settings = $engine->configureSoftDeleteFilter($settings);
            }

            $settings = Scout::prepareIndexSettings($settings, $model, $engine, $name);

            if ($settings) {
                $engine->updateIndexSettings($name, $settings);
            }
        }

        $this->info("Synchronized index [\"{$name}\"] successfully.");

        return self::SUCCESS;
    }

    /**
     * Create a search index.
     *
     * @param array<string, mixed> $options
     */
    protected function createIndex(Engine $engine, string $name, array $options): void
    {
        try {
            $engine->createIndex($name, $options);
        } catch (NotSupportedException) {
            // The engine creates indexes implicitly.
        }
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
