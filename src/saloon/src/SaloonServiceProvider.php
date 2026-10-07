<?php

declare(strict_types=1);

namespace Hypervel\Saloon;

use Hypervel\Cache\CacheManager;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Cache\Data\CachedResponse;
use Hypervel\Saloon\Console\Commands\ListCommand;
use Hypervel\Saloon\Console\Commands\MakeAuthenticator;
use Hypervel\Saloon\Console\Commands\MakeConnector;
use Hypervel\Saloon\Console\Commands\MakePlugin;
use Hypervel\Saloon\Console\Commands\MakeRequest;
use Hypervel\Saloon\Console\Commands\MakeResponse;
use Hypervel\Saloon\Http\RequestOptionValidator;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Support\ServiceProvider;

class SaloonServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/saloon.php', 'saloon');

        $this->app->singleton('saloon', fn ($app) => new SaloonManager(
            $app->make(Sender::class),
            $app->make(CacheFactory::class),
            $app->make(RateLimiter::class),
            $app->make(ConfigRepository::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->alias('saloon', SaloonManager::class);

        // Event fakes replace the dispatcher after the manager may already hold the original.
        $this->app->rebinding('events', function (Container $container, Dispatcher $events): void {
            if ($container->resolved('saloon')) {
                $container->make('saloon')->setEventDispatcher($events);
            }
        });
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(HttpFactory $httpFactory, ConfigRepository $config, CacheManager $cache): void
    {
        // Cached responses are stored as CachedResponse objects, which serializing stores must be allowed to restore.
        $cache->allowSerializableClassesUsing(static fn (): array => [CachedResponse::class]);

        $connection = $config->string('saloon.connection.name');
        $options = $config->array('saloon.connection.options');

        RequestOptionValidator::validate($options, "HTTP connection [{$connection}]");

        $httpFactory->registerConnection(
            $connection,
            $options,
        );

        // The plugin's Telescope, Pulse and Nightwatch middleware are not included: Saloon requests go through the
        // HTTP client, which Telescope's watcher records, and Hypervel has no Pulse or Nightwatch package.

        $this->registerConsoleResources();
    }

    /**
     * Register package console resources.
     */
    protected function registerConsoleResources(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ListCommand::class,
            MakeAuthenticator::class,
            MakeConnector::class,
            MakePlugin::class,
            MakeRequest::class,
            MakeResponse::class,
        ]);

        $this->publishes([
            __DIR__ . '/../config/saloon.php' => config_path('saloon.php'),
        ], 'saloon-config');

        $this->publishes([
            __DIR__ . '/../stubs/saloon.authenticator.stub' => base_path('stubs/saloon.authenticator.stub'),
            __DIR__ . '/../stubs/saloon.connector.stub' => base_path('stubs/saloon.connector.stub'),
            __DIR__ . '/../stubs/saloon.oauth-connector.stub' => base_path('stubs/saloon.oauth-connector.stub'),
            __DIR__ . '/../stubs/saloon.plugin.stub' => base_path('stubs/saloon.plugin.stub'),
            __DIR__ . '/../stubs/saloon.request.stub' => base_path('stubs/saloon.request.stub'),
            __DIR__ . '/../stubs/saloon.response.stub' => base_path('stubs/saloon.response.stub'),
        ], 'saloon-stubs');
    }
}
