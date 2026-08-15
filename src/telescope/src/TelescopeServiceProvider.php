<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Telescope\Actions\UninstallAction;
use Hypervel\Telescope\Aspects\GuzzleHttpClientAspect;
use Hypervel\Telescope\Contracts\ClearableRepository;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Contracts\PrunableRepository;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Watchers\CacheWatcher;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Telescope\Watchers\RedisWatcher;

class TelescopeServiceProvider extends ServiceProvider implements ReloadsConfiguration
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

        if (! config()->boolean('telescope.enabled')) {
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
                // fork() installs its snapshot before callbacks run, so keep captured values.
                if (CoroutineContext::has($key)) {
                    continue;
                }

                CoroutineContext::set($key, CoroutineContext::get($key, $default, Coroutine::parentId()));
            }
        });
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        Route::domain(config('telescope.domain'))
            ->middleware(config()->array('telescope.middleware'))
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
        $this->commands([
            Console\ClearCommand::class,
            Console\InstallCommand::class,
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

        $this->registerPrePackageUninstallListener();
        $this->registerStorageDriver();

        if (! config()->boolean('telescope.enabled')) {
            return;
        }

        $this->registerRedisEvents();
        $this->registerCacheEvents();
        $this->registerGuzzleHttpClientAspect();
    }

    /**
     * Reload the worker configuration owned by the provider.
     *
     * Boot-only. Calling this while requests are running mutates shared worker
     * state while concurrent coroutines may still use the previous configuration.
     */
    public function reloadConfiguration(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        foreach ([EntriesRepository::class, ClearableRepository::class, PrunableRepository::class] as $abstract) {
            if (! $this->app->resolved($abstract)) {
                continue;
            }

            $repository = $this->app->make($abstract);

            if ($repository instanceof DatabaseEntriesRepository) {
                $repository->setConnection($config->string('telescope.storage.database.connection'));
                $repository->setChunkSize($config->integer('telescope.storage.database.chunk'));
            }
        }
    }

    /**
     * Register the Redis events if the watcher is enabled.
     */
    protected function registerRedisEvents(): void
    {
        if (! $this->watcherIsEnabled(RedisWatcher::class)) {
            return;
        }

        RedisWatcher::enableRedisEvents($this->app);
    }

    /**
     * Register the Cache events if the watcher is enabled.
     */
    protected function registerCacheEvents(): void
    {
        if (! $this->watcherIsEnabled(CacheWatcher::class)) {
            return;
        }

        CacheWatcher::enableCacheEvents($this->app);
    }

    /**
     * Register the Guzzle HTTP client aspect if the watcher is enabled.
     */
    protected function registerGuzzleHttpClientAspect(): void
    {
        if (! $this->watcherIsEnabled(ClientRequestWatcher::class)) {
            return;
        }

        $this->aspects(GuzzleHttpClientAspect::class);
    }

    /**
     * Determine if the given watcher is enabled.
     */
    protected function watcherIsEnabled(string $watcher): bool
    {
        $config = config('telescope.watchers.' . $watcher, false);

        return (bool) $config && (! is_array($config) || ($config['enabled'] ?? true));
    }

    /**
     * Register the package storage driver.
     */
    protected function registerStorageDriver(): void
    {
        $driver = config()->string('telescope.driver');

        if (method_exists($this, $method = 'register' . ucfirst($driver) . 'Driver')) {
            $this->{$method}();
        }
    }

    /**
     * Register the package database storage driver.
     */
    protected function registerDatabaseDriver(): void
    {
        $config = $this->app->make(ConfigRepository::class);

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
            ->give(fn () => $config->string('telescope.storage.database.connection'));

        $this->app->when(DatabaseEntriesRepository::class)
            ->needs('$chunkSize')
            ->give(fn () => $config->integer('telescope.storage.database.chunk'));
    }

    /**
     * Register a pre-package uninstallation listener.
     */
    protected function registerPrePackageUninstallListener(): void
    {
        $this->app->make(Dispatcher::class)->listen('composer_package.hypervel/telescope:pre_uninstall', function (): void {
            $this->app->make(UninstallAction::class)->handle();
        });
    }
}
