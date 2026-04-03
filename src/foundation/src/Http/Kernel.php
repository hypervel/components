<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Foundation\Http\Events\RequestHandled;
use Hypervel\Http\Request;
use Hypervel\Routing\Pipeline;
use Hypervel\Routing\Router;
use Hypervel\Support\Carbon;
use Hypervel\Support\InteractsWithTime;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Kernel implements KernelContract
{
    use InteractsWithTime;

    /**
     * The application implementation.
     */
    protected Application $app;

    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The bootstrap classes for the application.
     *
     * @var string[]
     */
    protected array $bootstrappers = [
        \Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        \Hypervel\Foundation\Bootstrap\LoadConfiguration::class,
        \Hypervel\Foundation\Bootstrap\HandleExceptions::class,
        \Hypervel\Foundation\Bootstrap\RegisterFacades::class,
        \Hypervel\Foundation\Bootstrap\RegisterProviders::class,
        \Hypervel\Di\Bootstrap\GenerateProxies::class,
        \Hypervel\Foundation\Bootstrap\BootProviders::class,
    ];

    /**
     * The application's middleware stack.
     *
     * @var array<int, class-string|string>
     */
    protected array $middleware = [];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected array $middlewareGroups = [];

    /**
     * The application's middleware aliases.
     *
     * @var array<string, class-string|string>
     */
    protected array $middlewareAliases = [];

    /**
     * All of the registered request duration handlers.
     *
     * @var array<int, array{threshold: float|int, handler: callable}>
     */
    protected array $requestLifecycleDurationHandlers = [];

    /**
     * When the kernel started handling the current request.
     */
    protected ?Carbon $requestStartedAt = null;

    /**
     * The priority-sorted list of middleware.
     *
     * Forces non-global middleware to always be in the given order.
     *
     * @var string[]
     */
    protected array $middlewarePriority = [
        \Hypervel\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Hypervel\Cookie\Middleware\EncryptCookies::class,
        \Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Hypervel\Session\Middleware\StartSession::class,
        \Hypervel\View\Middleware\ShareErrorsFromSession::class,
        \Hypervel\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Hypervel\Routing\Middleware\ThrottleRequests::class,
        \Hypervel\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Hypervel\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Hypervel\Routing\Middleware\SubstituteBindings::class,
        \Hypervel\Auth\Middleware\Authorize::class,
    ];

    /**
     * Create a new HTTP kernel instance.
     */
    public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;

        $this->syncMiddlewareToRouter();
    }

    /**
     * Handle an incoming HTTP request.
     */
    public function handle(Request $request): Response
    {
        $this->requestStartedAt = Carbon::now();

        try {
            $request->enableHttpMethodParameterOverride();
            $response = $this->sendRequestThroughRouter($request);
        } catch (Throwable $e) {
            $this->reportException($e);

            $response = $this->renderException($request, $e);
        }

        $events = $this->app['events'];

        if ($events->hasListeners(RequestHandled::class)) {
            $events->dispatch(
                new RequestHandled($request, $response)
            );
        }

        return $response;
    }

    /**
     * Send the given request through the middleware / router.
     */
    protected function sendRequestThroughRouter(Request $request): Response
    {
        $this->bootstrap();
        $middleware = $this->app->shouldSkipMiddleware() ? [] : $this->middleware;

        if ($middleware === []) {
            return ($this->dispatchToRouter())($request);
        }

        return (new Pipeline($this->app))
            ->send($request)
            ->through($middleware)
            ->then($this->dispatchToRouter());
    }

    /**
     * Bootstrap the application for HTTP requests.
     */
    public function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    /**
     * Get the route dispatcher callback.
     *
     * Uses RequestContext::set() instead of Laravel's $this->app->instance('request', $request)
     * because instance() writes to process-global $instances which would race across coroutines.
     * The HttpServiceProvider's bind('request', fn () => RequestContext::get()) ensures all
     * resolution paths return the coroutine-local request.
     */
    protected function dispatchToRouter(): Closure
    {
        return function (Request $request) {
            RequestContext::set($request);

            return $this->router->dispatch($request);
        };
    }

    /**
     * Perform any final actions for the request lifecycle.
     */
    public function terminate(Request $request, Response $response): void
    {
        $events = $this->app['events'];

        if ($events->hasListeners(Terminating::class)) {
            $events->dispatch(new Terminating());
        }
        $this->terminateMiddleware($request, $response);
        $this->app->terminate();

        if ($this->requestStartedAt === null || $this->requestLifecycleDurationHandlers === []) {
            $this->requestStartedAt = null;

            return;
        }

        $this->requestStartedAt->setTimezone($this->app['config']->get('app.timezone') ?? 'UTC');

        foreach ($this->requestLifecycleDurationHandlers as ['threshold' => $threshold, 'handler' => $handler]) {
            $end ??= Carbon::now();

            if ($this->requestStartedAt->diffInMilliseconds($end) > $threshold) {
                $handler($this->requestStartedAt, $request, $response);
            }
        }

        $this->requestStartedAt = null;
    }

    /**
     * Call the terminate method on any terminable middleware.
     */
    protected function terminateMiddleware(Request $request, Response $response): void
    {
        if ($this->app->shouldSkipMiddleware()) {
            return;
        }

        $routeMiddleware = $this->gatherRouteMiddleware($request);

        if ($routeMiddleware === [] && $this->middleware === []) {
            return;
        }

        $middlewares = [...$routeMiddleware, ...$this->middleware];

        foreach ($middlewares as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name] = $this->parseMiddleware($middleware);

            $instance = $this->app->make($name);

            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }
    }

    /**
     * Register a callback to be invoked when the request lifecycle duration exceeds a given amount of time.
     */
    public function whenRequestLifecycleIsLongerThan(DateTimeInterface|CarbonInterval|float|int $threshold, callable $handler): void
    {
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->requestLifecycleDurationHandlers[] = [
            'threshold' => $threshold,
            'handler' => $handler,
        ];
    }

    /**
     * Get when the kernel started handling the current request.
     */
    public function requestStartedAt(): ?Carbon
    {
        return $this->requestStartedAt;
    }

    /**
     * Gather the route middleware for the given request.
     */
    protected function gatherRouteMiddleware(Request $request): array
    {
        if ($route = $request->route()) {
            return $this->router->gatherRouteMiddleware($route);
        }

        return [];
    }

    /**
     * Parse a middleware string to get the name and parameters.
     */
    protected function parseMiddleware(string $middleware): array
    {
        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, []);

        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }

    /**
     * Determine if the kernel has a given middleware.
     */
    public function hasMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->middleware);
    }

    /**
     * Add a new middleware to the beginning of the stack if it does not already exist.
     *
     * @return $this
     */
    public function prependMiddleware(string $middleware): static
    {
        if (array_search($middleware, $this->middleware) === false) {
            array_unshift($this->middleware, $middleware);
        }

        return $this;
    }

    /**
     * Add a new middleware to end of the stack if it does not already exist.
     *
     * @return $this
     */
    public function pushMiddleware(string $middleware): static
    {
        if (array_search($middleware, $this->middleware) === false) {
            $this->middleware[] = $middleware;
        }

        return $this;
    }

    /**
     * Prepend the given middleware to the given middleware group.
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function prependMiddlewareToGroup(string $group, string $middleware): static
    {
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            array_unshift($this->middlewareGroups[$group], $middleware);
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Append the given middleware to the given middleware group.
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function appendMiddlewareToGroup(string $group, string $middleware): static
    {
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            $this->middlewareGroups[$group][] = $middleware;
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Prepend the given middleware to the middleware priority list.
     *
     * @return $this
     */
    public function prependToMiddlewarePriority(string $middleware): static
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            array_unshift($this->middlewarePriority, $middleware);
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Append the given middleware to the middleware priority list.
     *
     * @return $this
     */
    public function appendToMiddlewarePriority(string $middleware): static
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            $this->middlewarePriority[] = $middleware;
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Add the given middleware to the middleware priority list before other middleware.
     *
     * @param array<int, string>|string $before
     * @return $this
     */
    public function addToMiddlewarePriorityBefore(string|array $before, string $middleware): static
    {
        return $this->addToMiddlewarePriorityRelative($before, $middleware, after: false);
    }

    /**
     * Add the given middleware to the middleware priority list after other middleware.
     *
     * @param array<int, string>|string $after
     * @return $this
     */
    public function addToMiddlewarePriorityAfter(string|array $after, string $middleware): static
    {
        return $this->addToMiddlewarePriorityRelative($after, $middleware);
    }

    /**
     * Add the given middleware to the middleware priority list relative to other middleware.
     *
     * @param array<int, string>|string $existing
     * @return $this
     */
    protected function addToMiddlewarePriorityRelative(string|array $existing, string $middleware, bool $after = true): static
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            $index = $after ? 0 : count($this->middlewarePriority);

            foreach ((array) $existing as $existingMiddleware) {
                if (in_array($existingMiddleware, $this->middlewarePriority)) {
                    $middlewareIndex = array_search($existingMiddleware, $this->middlewarePriority);

                    if ($after && $middlewareIndex > $index) {
                        $index = $middlewareIndex + 1;
                    } elseif ($after === false && $middlewareIndex < $index) {
                        $index = $middlewareIndex;
                    }
                }
            }

            if ($index === 0 && $after === false) {
                array_unshift($this->middlewarePriority, $middleware);
            } elseif (($after && $index === 0) || $index === count($this->middlewarePriority)) {
                $this->middlewarePriority[] = $middleware;
            } else {
                array_splice($this->middlewarePriority, $index, 0, $middleware);
            }
        }

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Sync the current state of the middleware to the router.
     */
    protected function syncMiddlewareToRouter(): void
    {
        $this->router->middlewarePriority = $this->middlewarePriority;

        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->router->middlewareGroup($key, $middleware);
        }

        foreach ($this->middlewareAliases as $key => $middleware) {
            $this->router->aliasMiddleware($key, $middleware);
        }
    }

    /**
     * Get the priority-sorted list of middleware.
     */
    public function getMiddlewarePriority(): array
    {
        return $this->middlewarePriority;
    }

    /**
     * Get the bootstrap classes for the application.
     *
     * @return string[]
     */
    protected function bootstrappers(): array
    {
        return $this->bootstrappers;
    }

    /**
     * Report the exception to the exception handler.
     */
    protected function reportException(Throwable $e): void
    {
        $this->app[ExceptionHandler::class]->report($e);
    }

    /**
     * Render the exception to a response.
     */
    protected function renderException(Request $request, Throwable $e): Response
    {
        return $this->app[ExceptionHandler::class]->render($request, $e);
    }

    /**
     * Get the application's global middleware.
     */
    public function getGlobalMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Set the application's global middleware.
     *
     * @return $this
     */
    public function setGlobalMiddleware(array $middleware): static
    {
        $this->middleware = $middleware;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Get the application's route middleware groups.
     */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }

    /**
     * Set the application's middleware groups.
     *
     * @return $this
     */
    public function setMiddlewareGroups(array $groups): static
    {
        $this->middlewareGroups = $groups;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Get the application's route middleware aliases.
     */
    public function getMiddlewareAliases(): array
    {
        return $this->middlewareAliases;
    }

    /**
     * Set the application's route middleware aliases.
     *
     * @return $this
     */
    public function setMiddlewareAliases(array $aliases): static
    {
        $this->middlewareAliases = $aliases;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Set the application's middleware priority.
     *
     * @return $this
     */
    public function setMiddlewarePriority(array $priority): static
    {
        $this->middlewarePriority = $priority;

        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Get the application instance.
     */
    public function getApplication(): Application
    {
        return $this->app;
    }

    /**
     * Set the application instance.
     *
     * @return $this
     */
    public function setApplication(Application $app): static
    {
        $this->app = $app;

        return $this;
    }
}
