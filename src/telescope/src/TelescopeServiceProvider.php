<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Telescope\Aspects\GuzzleHttpClientAspect;
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
        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }

        if (! config('telescope.enabled')) {
            return;
        }

        $this->registerRoutes();
        $this->registerResources();

        Telescope::start($this->app);
        Telescope::listenForStorageOpportunities($this->app);
        /* @phpstan-ignore-next-line */
        Coroutine::afterCreated(function () {
            $keys = [
                Telescope::SHOULD_RECORD_CONTEXT_KEY => false,
                Telescope::IS_RECORDING_CONTEXT_KEY => false,
                Telescope::BATCH_ID_CONTEXT_KEY => null,
            ];
            foreach ($keys as $key => $default) {
                CoroutineContext::set($key, CoroutineContext::get($key, $default, Coroutine::parentId()));
            }
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(config('telescope.middleware', []))
            ->prefix(config('telescope.path'))
            ->namespace('Hypervel\Telescope\Http\Controllers')
            ->group(__DIR__ . '/../routes/web.php');
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
        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'telescope-migrations');

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
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ClearCommand::class,
                Console\PauseCommand::class,
                Console\PruneCommand::class,
                Console\PublishCommand::class,
                Console\ResumeCommand::class,
            ]);
        }
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

        $this->aspects(GuzzleHttpClientAspect::class);
    }

    /**
     * Register the Redis events if the watcher is enabled.
     */
    protected function registerRedisEvents(): void
    {
        $config = config('telescope.watchers.' . RedisWatcher::class, false);

        if (! $config || (is_array($config) && ! ($config['enabled'] ?? true))) {
            return;
        }

        RedisWatcher::enableRedisEvents($this->app);
    }

    /**
     * Register the Cache events if the watcher is enabled.
     */
    protected function registerCacheEvents(): void
    {
        $config = config('telescope.watchers.' . CacheWatcher::class, false);

        if (! $config || (is_array($config) && ! ($config['enabled'] ?? true))) {
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
        $this->app->singleton(
            EntriesRepository::class,
            DatabaseEntriesRepository::class
        );

        $this->app->singleton(
            ClearableRepository::class,
            DatabaseEntriesRepository::class
        );

        $this->app->singleton(
            PrunableRepository::class,
            DatabaseEntriesRepository::class
        );

        $this->app->when(DatabaseEntriesRepository::class)
            ->needs('$connection')
            ->give(fn () => config('telescope.storage.database.connection'));

        $this->app->when(DatabaseEntriesRepository::class)
            ->needs('$chunkSize')
            ->give(fn () => config('telescope.storage.database.chunk'));
    }
}
