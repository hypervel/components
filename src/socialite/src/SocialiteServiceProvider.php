<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Support\ServiceProvider;

class SocialiteServiceProvider extends ServiceProvider implements ReloadsConfiguration
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->alias(SocialiteManager::class, Factory::class);
    }

    /**
     * Reload the worker configuration owned by the provider.
     *
     * Boot-only. Calling this while requests are running mutates shared worker
     * state while concurrent coroutines may still use the previous configuration.
     */
    public function reloadConfiguration(): void
    {
        if (! $this->app->resolved(SocialiteManager::class)) {
            return;
        }

        $this->app->make(SocialiteManager::class)->forgetDrivers();
    }
}
