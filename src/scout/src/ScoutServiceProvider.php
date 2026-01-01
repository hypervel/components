<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Support\ServiceProvider;
use Meilisearch\Client as MeilisearchClient;
use Typesense\Client as TypesenseClient;

class ScoutServiceProvider extends ServiceProvider
{
    /**
     * Register Scout services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/scout.php',
            'scout'
        );

        $this->app->bind(EngineManager::class, EngineManager::class);

        $this->app->bind(MeilisearchClient::class, function () {
            $config = $this->app->get(ConfigInterface::class);

            return new MeilisearchClient(
                $config->get('scout.meilisearch.host', 'http://localhost:7700'),
                $config->get('scout.meilisearch.key')
            );
        });

        $this->app->bind(TypesenseClient::class, function () {
            $config = $this->app->get(ConfigInterface::class);

            return new TypesenseClient(
                $config->get('scout.typesense.client-settings', [])
            );
        });
    }

    /**
     * Bootstrap Scout services.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            dirname(__DIR__) . '/config/scout.php' => config_path('scout.php'),
        ], 'scout-config');
    }

    /**
     * Register the package's Artisan commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            DeleteIndexCommand::class,
            FlushCommand::class,
            ImportCommand::class,
            IndexCommand::class,
            SyncIndexSettingsCommand::class,
        ]);
    }
}
