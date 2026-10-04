<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Support\Providers;

use Closure;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Routing\Router;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Traits\ForwardsCalls;

/**
 * @mixin Router
 */
class RouteServiceProvider extends ServiceProvider
{
    use ForwardsCalls;

    /**
     * The controller namespace for the application.
     */
    protected ?string $namespace = null;

    /**
     * The callback that should be used to load the application's routes.
     */
    protected ?Closure $loadRoutesUsing = null;

    /**
     * The global callback that should be used to load the application's routes.
     */
    protected static ?Closure $alwaysLoadRoutesUsing = null;

    /**
     * The callback that should be used to load the application's cached routes.
     */
    protected static ?Closure $alwaysLoadCachedRoutesUsing = null;

    /**
     * The callbacks that should be run before the application's routes are loaded.
     *
     * @var list<Closure>
     */
    protected static array $beforeLoadingRoutes = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->booted(function () {
            $this->setRootControllerNamespace();

            if ($this->routesAreCached()) {
                $this->loadCachedRoutes();
            } else {
                $this->loadRoutes();

                $this->app->booted(function () {
                    $routes = $this->app->make('router')->getRoutes();
                    $routes->refreshNameLookups();
                    $routes->refreshActionLookups();
                });
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }

    /**
     * Register the callback that will be used to load the application's routes.
     *
     * @return $this
     */
    protected function routes(Closure $routesCallback): static
    {
        $this->loadRoutesUsing = $routesCallback;

        return $this;
    }

    /**
     * Register the callback that will be used to load the application's routes.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs during route loading at boot.
     */
    public static function loadRoutesUsing(?Closure $routesCallback): void
    {
        self::$alwaysLoadRoutesUsing = $routesCallback;
    }

    /**
     * Register the callback that will be used to load the application's cached routes.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs during route loading at boot when route cache exists.
     */
    public static function loadCachedRoutesUsing(?Closure $routesCallback): void
    {
        self::$alwaysLoadCachedRoutesUsing = $routesCallback;
    }

    /**
     * Register a callback to run just before a route provider loads its routes.
     *
     * The routing provider registered by withRouting() boots after every other
     * provider, so the callback's routes see the patterns those providers register
     * and match before the application's later routes in the same domain scope,
     * though an application route with the same method, domain and URI replaces
     * them. Cached routes already contain them, so the callback does not run.
     *
     * Boot-only. The callback persists in a static property for the worker
     * lifetime and runs during route loading at boot.
     */
    public static function beforeLoadingRoutes(Closure $callback): void
    {
        self::$beforeLoadingRoutes[] = $callback;
    }

    /**
     * Set the root controller namespace for the application.
     */
    protected function setRootControllerNamespace(): void
    {
        if (! is_null($this->namespace)) {
            $this->app->make(UrlGenerator::class)->setRootControllerNamespace($this->namespace);
        }
    }

    /**
     * Determine if the application routes are cached.
     */
    protected function routesAreCached(): bool
    {
        return $this->app->routesAreCached();
    }

    /**
     * Load the cached routes for the application.
     */
    protected function loadCachedRoutes(): void
    {
        if (! is_null(self::$alwaysLoadCachedRoutesUsing)) {
            $this->app->call(self::$alwaysLoadCachedRoutesUsing);

            return;
        }

        $this->app->booted(function () {
            require $this->app->getCachedRoutesPath();
        });
    }

    /**
     * Load the application routes.
     */
    protected function loadRoutes(): void
    {
        foreach (self::$beforeLoadingRoutes as $callback) {
            $this->app->call($callback);
        }

        if (! is_null(self::$alwaysLoadRoutesUsing)) {
            $this->app->call(self::$alwaysLoadRoutesUsing);
        }

        if (! is_null($this->loadRoutesUsing)) {
            $this->app->call($this->loadRoutesUsing);
        } elseif (method_exists($this, 'map')) {
            $this->app->call([$this, 'map']);
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$alwaysLoadRoutesUsing = null;
        self::$alwaysLoadCachedRoutesUsing = null;
        self::$beforeLoadingRoutes = [];
    }

    /**
     * Pass dynamic methods onto the router instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo(
            $this->app->make(Router::class),
            $method,
            $parameters
        );
    }
}
