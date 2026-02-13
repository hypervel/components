<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Sentry\Aspects\CoroutineAspect;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\Commands\AboutCommand;
use Hypervel\Sentry\Commands\TestCommand;
use Hypervel\Sentry\Factory\ClientBuilderFactory;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\HttpClient\HttpClient;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Support\ServiceProvider;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Throwable;

class SentryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Keep Sentry's global singleton hub aligned with the current application container.
        SentrySdk::setCurrentHub($this->app->make(HubInterface::class));

        $this->bootFeatures();
        $this->registerPublishing();
        $this->registerCommands();

        /* @phpstan-ignore-next-line */
        Coroutine::afterCreated(function () {
            $keys = [
                Hub::CONTEXT_STACK_KEY => null,
            ];
            foreach ($keys as $key => $default) {
                Context::set($key, Context::get($key, $default, Coroutine::parentId()));
            }
        });
    }

    public static function getProviderConfig(): array
    {
        return [
            'aspects' => [
                GuzzleHttpClientAspect::class,
                CoroutineAspect::class,
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        \Sentry\SentrySdk::class => __DIR__ . '/../class_map/SentrySdk.php',
                    ],
                ],
            ],
        ];
    }

    public function register(): void
    {
        $this->app->extend(ClientBuilder::class, function (ClientBuilder $builder) {
            $transport = new HttpPoolTransport(
                new Pool(
                    $builder->getOptions(),
                    $this->app,
                    $this->app->make('config')->get('pools.sentry', [])
                )
            );

            return $builder->setTransport($transport);
        });

        $this->app->singleton(ClientInterface::class, function () {
            return $this->app
                ->make(ClientBuilder::class)
                ->getClient();
        });

        $this->app->singleton(ClientBuilder::class, fn () => (new ClientBuilderFactory())($this->app));
        $this->app->singleton(HubInterface::class, fn () => new Hub());
        $this->app->singleton(HttpClientInterface::class, fn () => new HttpClient(
            Version::getSdkIdentifier(),
            Version::getSdkVersion(),
        ));
        $this->registerFeatures();
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sentry.php' => config_path('sentry.php'),
        ], 'sentry-config');
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            AboutCommand::class,
            TestCommand::class,
        ]);
    }

    protected function registerFeatures(): void
    {
        $features = $this->app->make('config')->get('sentry.features', []);
        foreach ($features as $feature) {
            $this->app->singleton($feature, $feature);
        }

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $featureInstance->register();
            } catch (Throwable $e) {
                // Ensure that features do not break the whole application
            }
        }
    }

    protected function bootFeatures(): void
    {
        $features = $this->app->make('config')->get('sentry.features', []);
        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $featureInstance->boot();
            } catch (Throwable $e) {
                // Ensure that features do not break the whole application
            }
        }
    }
}
