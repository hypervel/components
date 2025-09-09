<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Support\ServiceProvider;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Throwable;

class SentryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootFeatures();
    }

    public function register(): void
    {
        $this->app->extend(ClientBuilder::class, function (ClientBuilder $builder) {
            $transport = new HttpPoolTransport(
                new Pool(
                    $builder->getOptions(),
                    $this->app,
                    $this->app->get(ConfigInterface::class)->get('pools.sentry', [])
                )
            );

            return $builder->setTransport($transport);
        });

        $this->app->define(ClientInterface::class, function () {
            return $this->app
                ->get(ClientBuilder::class)
                ->getClient();
        });

        $this->registerFeatures();
    }

    protected function registerFeatures(): void
    {
        $features = $this->app->get(ConfigInterface::class)->get('sentry.features', []);
        foreach ($features as $feature) {
            $this->app->bind($feature, function () use ($feature) {
                return new $feature($this->app);
            });
        }

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->get($feature);

                $featureInstance->register();
            } catch (Throwable $e) {
                // Ensure that features do not break the whole application
            }
        }
    }

    protected function bootFeatures(): void
    {
        $features = $this->app->get(ConfigInterface::class)->get('sentry.features', []);
        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->get($feature);

                $featureInstance->boot();
            } catch (Throwable $e) {
                // Ensure that features do not break the whole application
            }
        }
    }
}
