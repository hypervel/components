<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\HttpServer\CoreMiddleware as HttpServerCoreMiddleware;
use Hypervel\Support\ServiceProvider;

class HttpServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('request', fn ($app) => new Request());

        $this->app->singleton('response', fn ($app) => new Response());

        $this->app->singleton(HttpServerCoreMiddleware::class, CoreMiddleware::class);
    }
}
