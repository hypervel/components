<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Http\Discovery\ClassDiscovery;
use Hypervel\Context\RequestContext;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Discovery\GuzzlePsr18Strategy;
use Hypervel\Support\ServiceProvider;

class HttpServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerPsr18Discovery();
        $this->registerRequestFactory();
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(BeforeServerFork::class, function (): void {
            // The framework leaves Factory unbound, so a resolved concrete
            // identifies the auto-singleton that would cross the fork.
            if ($this->app->resolved(Factory::class)) {
                $this->app->make(Factory::class)->forgetConnectionHandlers();
            }
        });
    }

    /**
     * Register Guzzle as the preferred PSR-18 client for auto-discovery.
     *
     * Symfony's CurlHttpClient uses a shared CurlMultiHandle that is unsafe
     * when reused across Swoole coroutines. This ensures any package using
     * PSR-18 auto-discovery gets Guzzle instead.
     */
    protected function registerPsr18Discovery(): void
    {
        if (! class_exists(ClassDiscovery::class)) {
            return;
        }

        $strategies = ClassDiscovery::getStrategies();

        if (! in_array(GuzzlePsr18Strategy::class, $strategies, true)) {
            ClassDiscovery::prependStrategy(GuzzlePsr18Strategy::class);
        }
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
                ?? Request::create($app->make('config')->get('app.url') ?? 'http://localhost');
        });
    }
}
