<?php

declare(strict_types=1);

namespace Hypervel\Cookie;

use Hypervel\Support\ServiceProvider;

class CookieServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('cookie', fn ($app) => $app->make(CookieManager::class));
    }
}
