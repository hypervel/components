<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Http\Request;
use Hypervel\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerAuthenticator();
        $this->registerUserResolver();
        $this->registerAccessGate();
        $this->registerRequirePassword();
        $this->registerRequestUserResolver();
        $this->registerEventRebindHandler();
    }

    /**
     * Register the authenticator services.
     */
    protected function registerAuthenticator(): void
    {
        $this->app->singleton('auth', fn ($app) => new AuthManager($app));

        // bind() instead of singleton() because shouldUse() can change the
        // current default guard per-coroutine via Context. The actual guard
        // instances are still cached by AuthManager; this binding just needs
        // to resolve which cached guard is current at call time.
        $this->app->bind('auth.driver', fn ($app) => $app['auth']->guard());
    }

    /**
     * Register a resolver for the authenticated user.
     */
    protected function registerUserResolver(): void
    {
        // bind() is required here — each resolution must call the user resolver
        // fresh to get the current coroutine's authenticated user from Context.
        // A singleton would cache the first user and leak it across requests.
        $this->app->bind(AuthenticatableContract::class, fn ($app) => call_user_func($app['auth']->userResolver()));
    }

    /**
     * Register the access gate service.
     */
    protected function registerAccessGate(): void
    {
        $this->app->singleton(GateContract::class, function ($app) {
            return new Gate($app, fn () => call_user_func($app['auth']->userResolver()));
        });
    }

    /**
     * Register the require password middleware.
     */
    protected function registerRequirePassword(): void
    {
        $this->app->singleton(RequirePassword::class, function ($app) {
            return new RequirePassword(
                $app[ResponseFactory::class],
                $app[UrlGenerator::class],
                $app['config']->get('auth.password_timeout'),
            );
        });
    }

    /**
     * Set the user resolver on each resolved request instance.
     *
     * Uses callAfterResolving() instead of Laravel's rebinding('request', ...)
     * because Hypervel's request is bound via bind() and resolved from
     * RequestContext — it is not swapped via instance(), so rebinding
     * callbacks would never fire.
     */
    protected function registerRequestUserResolver(): void
    {
        $this->callAfterResolving(Request::class, function (Request $request) {
            $request->setUserResolver(function (?string $guard = null) {
                return call_user_func($this->app['auth']->userResolver(), $guard);
            });
        });
    }

    /**
     * Handle the re-binding of the event dispatcher binding.
     */
    protected function registerEventRebindHandler(): void
    {
        $this->app->rebinding('events', function ($app, $dispatcher) {
            if (! $app->resolved('auth')
                || $app['auth']->hasResolvedGuards() === false) {
                return;
            }

            foreach ($app['auth']->getGuards() as $guard) {
                if (method_exists($guard, 'setDispatcher')) {
                    $guard->setDispatcher($dispatcher);
                }
            }
        });
    }
}
