<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hyperf\Redis\RedisFactory;
use Hypervel\Event\Contracts\Dispatcher;
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
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Register the Horizon job events.
     */
    protected function registerEvents(): void
    {
        $events = $this->app->get(Dispatcher::class);

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
        Route::group(
            config('horizon.path'),
            __DIR__ . '/../routes/web.php',
            [
                // not support domain setting
                // 'domain' => config('horizon.domain', null),
                'namespace' => 'Hypervel\Horizon\Http\Controllers',
                'middleware' => config('horizon.middleware', ['web']),
            ]
        );
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
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/horizon.php' => config_path('horizon.php'),
        ], 'horizon-config');

        $this->publishes([
            __DIR__ . '/../stubs/HorizonServiceProvider.stub' => app_path('Providers/HorizonServiceProvider.php'),
        ], 'horizon-provider');
    }

    /**
     * Register the Horizon Artisan commands.
     */
    protected function registerCommands(): void
    {
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
                Console\PurgeCommand::class,
                Console\SupervisorCommand::class,
                Console\SupervisorStatusCommand::class,
                Console\TerminateCommand::class,
                Console\TimeoutCommand::class,
                Console\WorkCommand::class,
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
            $this->app->alias($value, $key);
        }
    }

    /**
     * Register the custom queue connectors for Horizon.
     */
    protected function registerQueueConnectors(): void
    {
        $this->callAfterResolving(QueueManager::class, function (QueueManager $manager) {
            $manager->addConnector('redis', function () {
                return new RedisConnector(
                    $this->app->get(RedisFactory::class)
                );
            });
        });
    }
}
