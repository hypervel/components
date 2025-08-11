<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Horizon\Connectors\RedisConnector;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class HorizonServiceProvider extends ServiceProvider
{
    use EventMap;
    use ServiceBindings;

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerEvents();
        $this->registerRoutes();
        $this->registerResources();
        $this->offerPublishing();
        $this->registerCommands();
    }

    /**
     * Register the Horizon job events.
     */
    protected function registerEvents(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->events as $event => $listeners) {
            foreach ($listeners as $listener) {
                $events->listen($event, $listener);
            }
        }
    }

    /**
     * Register the Horizon routes.
     */
    protected function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => config('horizon.domain', null),
            'prefix' => config('horizon.path'),
            'namespace' => 'Laravel\Horizon\Http\Controllers',
            'middleware' => config('horizon.middleware', 'web'),
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });
    }

    /**
     * Register the Horizon resources.
     */
    protected function registerResources(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'horizon');
    }

    /**
     * Setup the resource publishing groups for Horizon.
     */
    protected function offerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../stubs/HorizonServiceProvider.stub' => app_path('Providers/HorizonServiceProvider.php'),
            ], 'horizon-provider');

            $this->publishes([
                __DIR__ . '/../config/horizon.php' => config_path('horizon.php'),
            ], 'horizon-config');
        }
    }

    /**
     * Register the Horizon Artisan commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ClearCommand::class,
                Console\ClearMetricsCommand::class,
                Console\ContinueCommand::class,
                Console\ContinueSupervisorCommand::class,
                Console\ForgetFailedCommand::class,
                Console\HorizonCommand::class,
                Console\InstallCommand::class,
                Console\ListCommand::class,
                Console\PauseCommand::class,
                Console\PauseSupervisorCommand::class,
                Console\PublishCommand::class,
                Console\PurgeCommand::class,
                Console\SupervisorCommand::class,
                Console\SupervisorStatusCommand::class,
                Console\TerminateCommand::class,
                Console\TimeoutCommand::class,
                Console\WorkCommand::class,
            ]);
        }

        $this->commands([
            Console\SnapshotCommand::class,
            Console\StatusCommand::class,
            Console\SupervisorsCommand::class,
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (! defined('HORIZON_PATH')) {
            define('HORIZON_PATH', realpath(__DIR__ . '/../'));
        }

        $this->app->bind(Console\WorkCommand::class, function ($app) {
            return new Console\WorkCommand($app['queue.worker'], $app['cache.store']);
        });

        $this->configure();
        $this->registerServices();
        $this->registerQueueConnectors();
    }

    /**
     * Setup the configuration for Horizon.
     */
    protected function configure(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/horizon.php',
            'horizon'
        );

        Horizon::use(config('horizon.use', 'default'));
    }

    /**
     * Register Horizon's services in the container.
     */
    protected function registerServices(): void
    {
        foreach ($this->serviceBindings as $key => $value) {
            is_numeric($key)
                    ? $this->app->singleton($value)
                    : $this->app->singleton($key, $value);
        }
    }

    /**
     * Register the custom queue connectors for Horizon.
     */
    protected function registerQueueConnectors(): void
    {
        $this->callAfterResolving(QueueManager::class, function ($manager) {
            $manager->addConnector('redis', function () {
                return new RedisConnector($this->app['redis']);
            });
        });
    }
}
