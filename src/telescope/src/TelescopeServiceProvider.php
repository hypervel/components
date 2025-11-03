<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Telescope\Contracts\ClearableRepository;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Contracts\PrunableRepository;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Watchers\CacheWatcher;
use Hypervel\Telescope\Watchers\RedisWatcher;

class TelescopeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerPublishing();

        if (! config('telescope.enabled')) {
            return;
        }

        $this->registerRoutes();
        $this->registerResources();

        Telescope::start($this->app);
        Telescope::listenForStorageOpportunities($this->app);
        Coroutine::addAfterCreatingHook(function () {
            $keys = [
                Telescope::SHOULD_RECORD => false,
                Telescope::IS_RECORDING => false,
                Telescope::BATCH_ID => null,
            ];
            foreach ($keys as $key => $default) {
                Context::set($key, Context::get($key, $default, Coroutine::parentId()));
            }
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::group(
            config('telescope.path'),
            __DIR__ . '/../routes/web.php',
            [
                'namespace' => 'Hypervel\Telescope\Http\Controllers',
                'middleware' => config('telescope.middleware', []),
            ]
        );
    }

    /**
     * Register the Telescope resources.
     */
    protected function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'telescope');
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../database/migrations/2025_02_08_000000_create_telescope_entries_table.php' => database_path('migrations/2025_02_08_000000_create_telescope_entries_table.php'),
        ], 'telescope-migrations');

        $this->publishes([
            __DIR__ . '/../public' => public_path('vendor/telescope'),
        ], ['telescope-assets']);

        $this->publishes([
            __DIR__ . '/../config/telescope.php' => config_path('telescope.php'),
        ], 'telescope-config');

        $this->publishes([
            __DIR__ . '/../stubs/TelescopeServiceProvider.stub' => app_path('Providers/TelescopeServiceProvider.php'),
        ], 'telescope-provider');
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            Console\ClearCommand::class,
            Console\PauseCommand::class,
            Console\PruneCommand::class,
            Console\PublishCommand::class,
            Console\ResumeCommand::class,
        ]);
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/telescope.php',
            'telescope'
        );

        $this->registerStorageDriver();
        $this->registerRedisEvents();
        $this->registerCacheEvents();
    }

    /**
     * Register the Redis events if the watcher is enabled.
     */
    protected function registerRedisEvents(): void
    {
        if (! config('telescope.watchers.' . RedisWatcher::class, false)) {
            return;
        }

        RedisWatcher::enableRedisEvents($this->app);
    }

    /**
     * Register the Cache events if the watcher is enabled.
     */
    protected function registerCacheEvents(): void
    {
        if (! config('telescope.watchers.' . CacheWatcher::class, false)) {
            return;
        }

        CacheWatcher::enableCacheEvents($this->app);
    }

    /**
     * Register the package storage driver.
     */
    protected function registerStorageDriver(): void
    {
        $driver = config('telescope.driver');

        if (method_exists($this, $method = 'register' . ucfirst($driver) . 'Driver')) {
            $this->{$method}();
        }
    }

    /**
     * Register the package database storage driver.
     */
    protected function registerDatabaseDriver(): void
    {
        $this->app->bind(
            EntriesRepository::class,
            fn ($container) => $container->get(DatabaseEntriesRepository::class)
        );

        $this->app->bind(
            ClearableRepository::class,
            fn ($container) => $container->get(DatabaseEntriesRepository::class)
        );

        $this->app->bind(
            PrunableRepository::class,
            fn ($container) => $container->get(DatabaseEntriesRepository::class)
        );
    }
}
