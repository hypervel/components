<?php

declare(strict_types=1);

namespace Hypervel\Support;

class AggregateServiceProvider extends ServiceProvider
{
    /**
     * The provider class names.
     *
     * @var array<int, class-string<ServiceProvider>>
     */
    protected array $providers = [];

    /**
     * An array of the service provider instances.
     *
     * @var array<int, ServiceProvider>
     */
    protected array $instances = [];

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->instances = [];

        foreach ($this->providers as $provider) {
            $this->instances[] = $this->app->register($provider);
        }
    }
}
