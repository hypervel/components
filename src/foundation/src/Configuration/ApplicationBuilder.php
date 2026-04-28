<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Console\Application as Artisan;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Foundation\Support\Providers\EventServiceProvider as AppEventServiceProvider;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider as AppRouteServiceProvider;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\View;
use Laravel\Folio\Folio;
use Throwable;

class ApplicationBuilder
{
    /**
     * The service providers that are marked for registration.
     */
    protected array $pendingProviders = [];

    /**
     * Any additional routing callbacks that should be invoked while registering routes.
     */
    protected array $additionalRoutingCallbacks = [];

    /**
     * The Folio / page middleware that have been defined by the user.
     */
    protected array $pageMiddleware = [];

    /**
     * Create a new application builder instance.
     */
    public function __construct(protected Application $app)
    {
    }

    /**
     * Register the standard kernel classes for the application.
     */
    public function withKernels(): static
    {
        $this->app->singleton(
            \Hypervel\Contracts\Http\Kernel::class,
            \Hypervel\Foundation\Http\Kernel::class,
        );

        $this->app->singleton(
            \Hypervel\Contracts\Console\Kernel::class,
            \Hypervel\Foundation\Console\Kernel::class,
        );

        return $this;
    }

    /**
     * Register additional service providers.
     */
    public function withProviders(array $providers = [], bool $withBootstrapProviders = true): static
    {
        RegisterProviders::merge(
            $providers,
            $withBootstrapProviders
                ? $this->app->getBootstrapProvidersPath()
                : null
        );

        return $this;
    }

    /**
     * Register the core event service provider for the application.
     *
     * @param bool|iterable<int, string> $discover
     */
    public function withEvents(iterable|bool $discover = true): static
    {
        if (is_iterable($discover)) {
            AppEventServiceProvider::setEventDiscoveryPaths($discover);
        }

        if ($discover === false) {
            AppEventServiceProvider::disableEventDiscovery();
        }

        if (! isset($this->pendingProviders[AppEventServiceProvider::class])) {
            $this->app->booting(function () {
                $this->app->register(AppEventServiceProvider::class);
            });
        }

        $this->pendingProviders[AppEventServiceProvider::class] = true;

        return $this;
    }

    /**
     * Register the broadcasting services for the application.
     */
    public function withBroadcasting(string $channels, array $attributes = []): static
    {
        $this->app->booted(function () use ($channels, $attributes) {
            Broadcast::routes(! empty($attributes) ? $attributes : null);

            if (file_exists($channels)) {
                require $channels;
            }
        });

        return $this;
    }

    /**
     * Register the routing services for the application.
     */
    public function withRouting(
        ?Closure $using = null,
        array|string|null $web = null,
        array|string|null $api = null,
        ?string $commands = null,
        ?string $channels = null,
        ?string $pages = null,
        ?string $health = null,
        string $apiPrefix = 'api',
        ?callable $then = null,
    ): static {
        if (is_null($using) && (is_string($web) || is_array($web) || is_string($api) || is_array($api) || is_string($pages) || is_string($health)) || is_callable($then)) {
            $using = $this->buildRoutingCallback($web, $api, $pages, $health, $apiPrefix, $then);

            if (is_string($health)) {
                PreventRequestsDuringMaintenance::except($health);
            }
        }

        AppRouteServiceProvider::loadRoutesUsing($using);

        $this->app->booting(function () {
            $this->app->register(AppRouteServiceProvider::class, force: true);
        });

        if (is_string($commands) && realpath($commands) !== false) {
            $this->withCommands([$commands]);
        }

        if (is_string($channels) && realpath($channels) !== false) {
            $this->withBroadcasting($channels);
        }

        return $this;
    }

    /**
     * Create the routing callback for the application.
     *
     * @throws Throwable
     */
    protected function buildRoutingCallback(
        array|string|null $web,
        array|string|null $api,
        ?string $pages,
        ?string $health,
        string $apiPrefix,
        ?callable $then,
    ): Closure {
        return function () use ($web, $api, $pages, $health, $apiPrefix, $then) {
            if (is_string($api) || is_array($api)) {
                if (is_array($api)) {
                    foreach ($api as $apiRoute) {
                        if (realpath($apiRoute) !== false) {
                            Route::middleware('api')->prefix($apiPrefix)->group($apiRoute);
                        }
                    }
                } else {
                    Route::middleware('api')->prefix($apiPrefix)->group($api);
                }
            }

            if (is_string($health)) {
                Route::get($health, function () {
                    $exception = null;

                    try {
                        Event::dispatch(new DiagnosingHealth);
                    } catch (Throwable $e) {
                        if (app()->hasDebugModeEnabled()) {
                            throw $e;
                        }

                        report($e);

                        $exception = $e->getMessage();
                    }

                    return response(View::file(__DIR__ . '/../resources/health-up.blade.php', [
                        'exception' => $exception,
                    ]), status: $exception ? 500 : 200);
                });
            }

            if (is_string($web) || is_array($web)) {
                if (is_array($web)) {
                    foreach ($web as $webRoute) {
                        if (realpath($webRoute) !== false) {
                            Route::middleware('web')->group($webRoute);
                        }
                    }
                } else {
                    Route::middleware('web')->group($web);
                }
            }

            foreach ($this->additionalRoutingCallbacks as $callback) {
                $callback();
            }

            if (is_string($pages)
                && realpath($pages) !== false
                && class_exists(Folio::class)) {
                Folio::route($pages, middleware: $this->pageMiddleware);
            }

            if (is_callable($then)) {
                $then($this->app);
            }
        };
    }

    /**
     * Register the global middleware, middleware groups, and middleware aliases for the application.
     */
    public function withMiddleware(?callable $callback = null): static
    {
        $this->app->afterResolving(HttpKernel::class, function ($kernel) use ($callback) {
            $middleware = (new Middleware)
                ->redirectGuestsTo(fn () => route('login'));

            if (! is_null($callback)) {
                $callback($middleware);
            }

            $this->pageMiddleware = $middleware->getPageMiddleware();
            $kernel->setGlobalMiddleware($middleware->getGlobalMiddleware());
            $kernel->setMiddlewareGroups($middleware->getMiddlewareGroups());
            $kernel->setMiddlewareAliases($middleware->getMiddlewareAliases());

            if ($priorities = $middleware->getMiddlewarePriority()) {
                $kernel->setMiddlewarePriority($priorities);
            }

            if ($priorityAppends = $middleware->getMiddlewarePriorityAppends()) {
                foreach ($priorityAppends as $newMiddleware => $after) {
                    $kernel->addToMiddlewarePriorityAfter($after, $newMiddleware);
                }
            }

            if ($priorityPrepends = $middleware->getMiddlewarePriorityPrepends()) {
                foreach ($priorityPrepends as $newMiddleware => $before) {
                    $kernel->addToMiddlewarePriorityBefore($before, $newMiddleware);
                }
            }
        });

        $this->app->afterResolving(ConsoleKernel::class, function () use ($callback) {
            if (! is_null($callback)) {
                $callback(new Middleware);
            }
        });

        return $this;
    }

    /**
     * Register additional Artisan commands with the application.
     */
    public function withCommands(array $commands = []): static
    {
        if (empty($commands)) {
            $commands = [$this->app->path('Console/Commands')];
        }

        $this->app->afterResolving(ConsoleKernel::class, function ($kernel) use ($commands) {
            [$commands, $paths] = (new Collection($commands))->partition(fn ($command) => class_exists($command));
            [$routes, $paths] = $paths->partition(fn ($path) => is_file($path));

            $this->app->booted(static function () use ($kernel, $commands, $paths, $routes) {
                $kernel->addCommands($commands->all());
                $kernel->addCommandPaths($paths->all());
                $kernel->addCommandRoutePaths($routes->all());
            });
        });

        return $this;
    }

    /**
     * Register additional Artisan route paths.
     */
    protected function withCommandRouting(array $paths): static
    {
        $this->app->afterResolving(ConsoleKernel::class, function ($kernel) use ($paths) {
            $this->app->booted(fn () => $kernel->addCommandRoutePaths($paths));
        });

        return $this;
    }

    /**
     * Register the scheduled tasks for the application.
     */
    public function withSchedule(callable $callback): static
    {
        Artisan::starting(function () use ($callback) {
            $this->app->afterResolving(Schedule::class, fn ($schedule) => $callback($schedule));

            if ($this->app->resolved(Schedule::class)) {
                $callback($this->app->make(Schedule::class));
            }
        });

        return $this;
    }

    /**
     * Register and configure the application's exception handler.
     *
     * @param null|(callable(Exceptions): void) $using
     */
    public function withExceptions(?callable $using = null): static
    {
        $this->app->singleton(
            \Hypervel\Contracts\Debug\ExceptionHandler::class,
            \Hypervel\Foundation\Exceptions\Handler::class
        );

        if ($using !== null) {
            $this->app->afterResolving(
                \Hypervel\Foundation\Exceptions\Handler::class,
                fn ($handler) => $using(new Exceptions($handler)),
            );
        }

        return $this;
    }

    /**
     * Register an array of container bindings to be bound when the application is booting.
     */
    public function withBindings(array $bindings): static
    {
        return $this->registered(function ($app) use ($bindings) {
            foreach ($bindings as $abstract => $concrete) {
                $app->bind($abstract, $concrete);
            }
        });
    }

    /**
     * Register an array of singleton container bindings to be bound when the application is booting.
     */
    public function withSingletons(array $singletons): static
    {
        return $this->registered(function ($app) use ($singletons) {
            foreach ($singletons as $abstract => $concrete) {
                if (is_string($abstract)) {
                    $app->singleton($abstract, $concrete);
                } else {
                    $app->singleton($concrete);
                }
            }
        });
    }

    /**
     * Register an array of scoped singleton container bindings to be bound when the application is booting.
     */
    public function withScopedSingletons(array $scopedSingletons): static
    {
        return $this->registered(function ($app) use ($scopedSingletons) {
            foreach ($scopedSingletons as $abstract => $concrete) {
                if (is_string($abstract)) {
                    $app->scoped($abstract, $concrete);
                } else {
                    $app->scoped($concrete);
                }
            }
        });
    }

    /**
     * Register a callback to be invoked when the application's service providers are registered.
     */
    public function registered(callable $callback): static
    {
        $this->app->registered($callback);

        return $this;
    }

    /**
     * Register a callback to be invoked when the application is "booting".
     */
    public function booting(callable $callback): static
    {
        $this->app->booting($callback);

        return $this;
    }

    /**
     * Register a callback to be invoked when the application is "booted".
     */
    public function booted(callable $callback): static
    {
        $this->app->booted($callback);

        return $this;
    }

    /**
     * Get the application instance.
     */
    public function create(): Application
    {
        return $this->app;
    }
}
