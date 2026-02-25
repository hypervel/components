<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Support\ServiceProvider;

class SocialiteServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(Factory::class, SocialiteManager::class);
    }
}
