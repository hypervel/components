<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Support\ServiceProvider;

class HttpServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerRequestFactory();
        $this->registerResponseFactory();
    }

    /**
     * Register the request factory.
     *
     * Uses bind() (not singleton) so every resolution call goes through
     * RequestContext, which is coroutine-local. This ensures app('request'),
     * the request() helper, and any DI resolution of Hypervel\Http\Request
     * all return the coroutine-local request stored by the adapter's
     * RequestContext::set($request).
     *
     * Falls back to a default request when no request exists in context
     * (console commands, early bootstrap, test setup before HTTP dispatch).
     * This mirrors Laravel's SetRequestForConsole bootstrapper.
     */
    protected function registerRequestFactory(): void
    {
        $this->app->bind('request', function ($app) {
            return RequestContext::getOrNull()
                ?? Request::create($app->make('config')->get('app.url', 'http://localhost'));
        });
    }

    /**
     * Register the response factory.
     *
     * Uses bind() (not singleton) so every resolution call goes through
     * ResponseContext, which is coroutine-local. This ensures any DI
     * resolution of Hypervel\Http\Response returns the coroutine-local
     * response stored by the HTTP server adapter's ResponseContext::set().
     *
     * Falls back to a bare Response when no response exists in context
     * (console commands, early bootstrap, test setup before HTTP dispatch).
     */
    protected function registerResponseFactory(): void
    {
        $this->app->bind(Response::class, fn () => ResponseContext::getOrNull() ?? new Response());
    }
}
