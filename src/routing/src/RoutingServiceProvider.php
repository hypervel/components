<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Closure;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Routing\Console\ControllerMakeCommand;
use Hypervel\Routing\Console\MiddlewareMakeCommand;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Support\ServiceProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class RoutingServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerRouter();
        $this->registerUrlGenerator();
        $this->registerRedirector();
        $this->registerPsrRequest();
        $this->registerPsrResponse();
        $this->registerResponseFactory();
        $this->registerCallableDispatcher();
        $this->registerControllerDispatcher();
        $this->registerCurrentRouteBinding();

        $this->commands([
            ControllerMakeCommand::class,
            MiddlewareMakeCommand::class,
        ]);
    }

    /**
     * Register the router instance.
     */
    protected function registerRouter(): void
    {
        $this->app->singleton('router', function ($app) {
            return new Router($app['events'], $app);
        });
    }

    /**
     * Register the URL generator service.
     */
    protected function registerUrlGenerator(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            // The URL generator needs the route collection that exists on the router.
            // Keep in mind this is an object, so we're passing by references here
            // and all the registered routes will be available to the generator.
            $app->instance('routes', $routes);

            return new UrlGenerator(
                $routes,
                $app->rebinding(
                    'request',
                    $this->requestRebinder()
                ),
                $app['config']['app.asset_url']
            );
        });

        $this->app->extend('url', function (UrlGenerator $url, $app) {
            $url->setSessionResolver(function () {
                return $this->app['session'] ?? null;
            });

            $url->setKeyResolver(function () {
                $config = $this->app->make('config');

                return [$config->get('app.key'), ...($config->get('app.previous_keys') ?? [])];
            });

            // If the route collection is "rebound", for example, when the routes stay
            // cached for the application, we will need to rebind the routes on the
            // URL generator instance so it has the latest version of the routes.
            $app->rebinding('routes', function ($app, $routes) {
                $app['url']->setRoutes($routes);
            });

            return $url;
        });
    }

    /**
     * Get the URL generator request rebinder.
     */
    protected function requestRebinder(): Closure
    {
        return function ($app, $request) {
            $app['url']->setRequest($request);
        };
    }

    /**
     * Register the Redirector service.
     */
    protected function registerRedirector(): void
    {
        $this->app->singleton('redirect', function ($app) {
            $redirector = new Redirector($app['url']);

            // If the session is set on the application instance, we'll inject it into
            // the redirector instance. This allows the redirect responses to allow
            // for the quite convenient "with" methods that flash to the session.
            if (isset($app['session.store'])) {
                $redirector->setSession($app['session.store']);
            }

            return $redirector;
        });
    }

    /**
     * Register a binding for the PSR-7 request implementation.
     */
    protected function registerPsrRequest(): void
    {
        $this->app->bind(ServerRequestInterface::class, function ($app) {
            if (class_exists(PsrHttpFactory::class)) {
                $illuminateRequest = $app->make('request');
                $request = (new PsrHttpFactory)->createRequest($illuminateRequest);

                if ($illuminateRequest->getContentTypeFormat() !== 'json' && $illuminateRequest->request->count() === 0) {
                    return $request;
                }

                return $request->withParsedBody(
                    array_merge($request->getParsedBody() ?? [], $illuminateRequest->getPayload()->all())
                );
            }

            throw new BindingResolutionException('Unable to resolve PSR request. Please install the "symfony/psr-http-message-bridge" package.');
        });
    }

    /**
     * Register a binding for the PSR-7 response implementation.
     */
    protected function registerPsrResponse(): void
    {
        $this->app->bind(ResponseInterface::class, function () {
            if (class_exists(PsrHttpFactory::class)) {
                return (new PsrHttpFactory)->createResponse(new Response);
            }

            throw new BindingResolutionException('Unable to resolve PSR response. Please install the "symfony/psr-http-message-bridge" package.');
        });
    }

    /**
     * Register the response factory implementation.
     */
    protected function registerResponseFactory(): void
    {
        $this->app->singleton(ResponseFactoryContract::class, function ($app) {
            return new ResponseFactory($app[ViewFactoryContract::class], $app['redirect']);
        });
    }

    /**
     * Register the callable dispatcher.
     */
    protected function registerCallableDispatcher(): void
    {
        $this->app->singleton(CallableDispatcherContract::class, function ($app) {
            return new CallableDispatcher($app);
        });
    }

    /**
     * Register the controller dispatcher.
     */
    protected function registerControllerDispatcher(): void
    {
        $this->app->singleton(ControllerDispatcherContract::class, function ($app) {
            return new ControllerDispatcher($app);
        });
    }

    /**
     * Register a coroutine-safe binding for the current Route.
     *
     * Laravel uses $container->instance(Route::class, $route) in Router::findRoute(),
     * but instance() writes to process-global $instances which races across coroutines.
     * Instead, we register a bind() factory that reads the current route from the
     * Router (which stores it in coroutine-local Context). Same pattern as the
     * request binding in HttpServiceProvider.
     */
    protected function registerCurrentRouteBinding(): void
    {
        $this->app->bind(Route::class, function ($app) {
            return $app->make('router')->current();
        });
    }
}
