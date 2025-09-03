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

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerEvents();
        $this->registerRoutes();
        $this->registerResources();
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
            fn () => $this->loadRoutesFrom(__DIR__ . '/../routes/web.php'),
            [
                // not support domain setting
                // 'domain' => config('horizon.domain', null),
                'namespace' => 'Laravel\Horizon\Http\Controllers',
                'middleware' => config('horizon.middleware', 'web'),
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
     * Register any application services.
     */
    public function register(): void
    {
        if (! defined('HORIZON_PATH')) {
            define('HORIZON_PATH', realpath(__DIR__ . '/../'));
        }

        $this->configure();
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
