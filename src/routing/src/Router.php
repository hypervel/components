<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use ArrayObject;
use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Contracts\Routing\Registrar as RegistrarContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Routing\Events\PreparingResponse;
use Hypervel\Routing\Events\ResponsePrepared;
use Hypervel\Routing\Events\RouteMatched;
use Hypervel\Routing\Events\Routing;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use ReflectionClass;
use stdClass;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * @mixin \Hypervel\Routing\RouteRegistrar
 */
class Router implements BindingRegistrar, RegistrarContract
{
    use Macroable {
        __call as macroCall;
    }
    use Tappable;

    private const CURRENT_ROUTE_CONTEXT_KEY = '__routing.current_route';

    private const CURRENT_REQUEST_CONTEXT_KEY = '__routing.current_request';

    /**
     * The event dispatcher instance.
     */
    protected Dispatcher $events;

    /**
     * The IoC container instance.
     */
    protected Container $container;

    /**
     * The route collection instance.
     */
    protected RouteCollectionInterface $routes;

    /**
     * All of the short-hand keys for middlewares.
     *
     * @var array<string, string>
     */
    protected array $middleware = [];

    /**
     * All of the middleware groups.
     *
     * @var array<string, array<int, string>>
     */
    protected array $middlewareGroups = [];

    /**
     * The priority-sorted list of middleware.
     *
     * Forces the listed middleware to always be in the given order.
     *
     * @var array<int, string>
     */
    public array $middlewarePriority = [];

    /**
     * The registered route value binders.
     *
     * @var array<string, Closure>
     */
    protected array $binders = [];

    /**
     * The globally available parameter patterns.
     *
     * @var array<string, string>
     */
    protected array $patterns = [];

    /**
     * The route group attribute stack.
     */
    protected array $groupStack = [];

    /**
     * The registered custom implicit binding callback.
     */
    protected Closure|array|null $implicitBindingCallback = null;

    /**
     * All of the verbs supported by the router.
     *
     * @var array<int, string>
     */
    public static array $verbs = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Create a new Router instance.
     */
    public function __construct(Dispatcher $events, ?Container $container = null)
    {
        $this->events = $events;
        $this->routes = new RouteCollection();
        $this->container = $container ?: new Container();
    }

    /**
     * Register a new GET route with the router.
     */
    public function get(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    /**
     * Register a new POST route with the router.
     */
    public function post(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * Register a new PUT route with the router.
     */
    public function put(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * Register a new PATCH route with the router.
     */
    public function patch(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    /**
     * Register a new DELETE route with the router.
     */
    public function delete(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * Register a new OPTIONS route with the router.
     */
    public function options(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute('OPTIONS', $uri, $action);
    }

    /**
     * Register a new route responding to all verbs.
     */
    public function any(string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute(self::$verbs, $uri, $action);
    }

    /**
     * Register a new fallback route with the router.
     */
    public function fallback(array|string|callable|null $action): Route
    {
        $placeholder = 'fallbackPlaceholder';

        return $this->addRoute(
            'GET',
            "{{$placeholder}}",
            $action
        )->where($placeholder, '.*')->fallback();
    }

    /**
     * Create a redirect from one URI to another.
     */
    public function redirect(string $uri, string $destination, int $status = 302): Route
    {
        return $this->any($uri, '\\' . RedirectController::class)
            ->defaults('destination', $destination)
            ->defaults('status', $status);
    }

    /**
     * Create a permanent redirect from one URI to another.
     */
    public function permanentRedirect(string $uri, string $destination): Route
    {
        return $this->redirect($uri, $destination, 301);
    }

    /**
     * Register a new route that returns a view.
     */
    public function view(string $uri, string $view, array $data = [], int|array $status = 200, array $headers = []): Route
    {
        return $this->match(['GET', 'HEAD'], $uri, '\\' . ViewController::class)
            ->setDefaults([
                'view' => $view,
                'data' => $data,
                'status' => is_array($status) ? 200 : $status,
                'headers' => is_array($status) ? $status : $headers,
            ]);
    }

    /**
     * Register a new route with the given verbs.
     */
    public function match(array|string $methods, string $uri, array|string|callable|null $action = null): Route
    {
        return $this->addRoute(array_map(strtoupper(...), (array) $methods), $uri, $action);
    }

    /**
     * Register an array of resource controllers.
     */
    public function resources(array $resources, array $options = []): void
    {
        foreach ($resources as $name => $controller) {
            $this->resource($name, $controller, $options);
        }
    }

    /**
     * Register an array of resource controllers that can be soft deleted.
     */
    public function softDeletableResources(array $resources, array $options = []): void
    {
        foreach ($resources as $name => $controller) {
            $this->resource($name, $controller, $options)->withTrashed();
        }
    }

    /**
     * Route a resource to a controller.
     */
    public function resource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        if ($this->container->bound(ResourceRegistrar::class)) {
            $registrar = $this->container->make(ResourceRegistrar::class);
        } else {
            $registrar = new ResourceRegistrar($this);
        }

        return new PendingResourceRegistration(
            $registrar,
            $name,
            $controller,
            $options
        );
    }

    /**
     * Register an array of API resource controllers.
     */
    public function apiResources(array $resources, array $options = []): void
    {
        foreach ($resources as $name => $controller) {
            $this->apiResource($name, $controller, $options);
        }
    }

    /**
     * Route an API resource to a controller.
     */
    public function apiResource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        $only = ['index', 'show', 'store', 'update', 'destroy'];

        if (isset($options['except'])) {
            $only = array_diff($only, (array) $options['except']);
        }

        return $this->resource($name, $controller, array_merge([
            'only' => $only,
        ], $options));
    }

    /**
     * Register an array of singleton resource controllers.
     */
    public function singletons(array $singletons, array $options = []): void
    {
        foreach ($singletons as $name => $controller) {
            $this->singleton($name, $controller, $options);
        }
    }

    /**
     * Route a singleton resource to a controller.
     */
    public function singleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration
    {
        if ($this->container->bound(ResourceRegistrar::class)) {
            $registrar = $this->container->make(ResourceRegistrar::class);
        } else {
            $registrar = new ResourceRegistrar($this);
        }

        return new PendingSingletonResourceRegistration(
            $registrar,
            $name,
            $controller,
            $options
        );
    }

    /**
     * Register an array of API singleton resource controllers.
     */
    public function apiSingletons(array $singletons, array $options = []): void
    {
        foreach ($singletons as $name => $controller) {
            $this->apiSingleton($name, $controller, $options);
        }
    }

    /**
     * Route an API singleton resource to a controller.
     */
    public function apiSingleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration
    {
        $only = ['store', 'show', 'update', 'destroy'];

        if (isset($options['except'])) {
            $only = array_diff($only, (array) $options['except']);
        }

        return $this->singleton($name, $controller, array_merge([
            'only' => $only,
        ], $options));
    }

    /**
     * Create a route group with shared attributes.
     *
     * @return $this
     */
    public function group(array $attributes, Closure|array|string $routes): static
    {
        foreach (Arr::wrap($routes) as $groupRoutes) {
            $this->updateGroupStack($attributes);

            // Once we have updated the group stack, we'll load the provided routes and
            // merge in the group's attributes when the routes are created. After we
            // have created the routes, we will pop the attributes off the stack.
            $this->loadRoutes($groupRoutes);

            array_pop($this->groupStack);
        }

        return $this;
    }

    /**
     * Update the group stack with the given attributes.
     */
    protected function updateGroupStack(array $attributes): void
    {
        if ($this->hasGroupStack()) {
            $attributes = $this->mergeWithLastGroup($attributes);
        }

        $this->groupStack[] = $attributes;
    }

    /**
     * Merge the given array with the last group stack.
     */
    public function mergeWithLastGroup(array $new, bool $prependExistingPrefix = true): array
    {
        return RouteGroup::merge($new, array_last($this->groupStack), $prependExistingPrefix);
    }

    /**
     * Load the provided routes.
     */
    protected function loadRoutes(Closure|string $routes): void
    {
        if ($routes instanceof Closure) {
            $routes($this);
        } else {
            (new RouteFileRegistrar($this))->register($routes);
        }
    }

    /**
     * Get the prefix from the last group on the stack.
     */
    public function getLastGroupPrefix(): string
    {
        if ($this->hasGroupStack()) {
            $last = array_last($this->groupStack);

            return $last['prefix'] ?? '';
        }

        return '';
    }

    /**
     * Add a route to the underlying route collection.
     */
    public function addRoute(array|string $methods, string $uri, array|string|callable|null $action): Route
    {
        return $this->routes->add($this->createRoute($methods, $uri, $action));
    }

    /**
     * Create a new route instance.
     */
    protected function createRoute(array|string $methods, string $uri, mixed $action): Route
    {
        // If the route is routing to a controller we will parse the route action into
        // an acceptable array format before registering it and creating this route
        // instance itself. We need to build the Closure that will call this out.
        if ($this->actionReferencesController($action)) {
            $action = $this->convertToControllerAction($action);
        }

        $route = $this->newRoute(
            $methods,
            $this->prefix($uri),
            $action
        );

        // If we have groups that need to be merged, we will merge them now after this
        // route has already been created and is ready to go. After we're done with
        // the merge we will be ready to return the route back out to the caller.
        if ($this->hasGroupStack()) {
            $this->mergeGroupAttributesIntoRoute($route);
        }

        $this->addWhereClausesToRoute($route);

        return $route;
    }

    /**
     * Determine if the action is routing to a controller.
     */
    protected function actionReferencesController(mixed $action): bool
    {
        if (! $action instanceof Closure) {
            return is_string($action) || (isset($action['uses']) && is_string($action['uses']));
        }

        return false;
    }

    /**
     * Add a controller based route action to the action array.
     */
    protected function convertToControllerAction(array|string $action): array
    {
        if (is_string($action)) {
            $action = ['uses' => $action];
        }

        // Here we'll merge any group "controller" and "uses" statements if necessary so that
        // the action has the proper clause for this property. Then, we can simply set the
        // name of this controller on the action plus return the action array for usage.
        if ($this->hasGroupStack()) {
            $action['uses'] = $this->prependGroupController($action['uses']);
            $action['uses'] = $this->prependGroupNamespace($action['uses']);
        }

        // Here we will set this controller name on the action array just so we always
        // have a copy of it for reference if we need it. This can be used while we
        // search for a controller name or do some other type of fetch operation.
        $action['controller'] = $action['uses'];

        return $action;
    }

    /**
     * Prepend the last group namespace onto the use clause.
     */
    protected function prependGroupNamespace(string $class): string
    {
        $group = array_last($this->groupStack);

        return isset($group['namespace']) && ! str_starts_with($class, '\\') && ! str_starts_with($class, $group['namespace'])
            ? $group['namespace'] . '\\' . $class
            : $class;
    }

    /**
     * Prepend the last group controller onto the use clause.
     */
    protected function prependGroupController(string $class): string
    {
        $group = array_last($this->groupStack);

        if (! isset($group['controller'])) {
            return $class;
        }

        if (class_exists($class)) {
            return $class;
        }

        if (str_contains($class, '@')) {
            return $class;
        }

        return $group['controller'] . '@' . $class;
    }

    /**
     * Create a new Route object.
     */
    public function newRoute(array|string $methods, string $uri, mixed $action): Route
    {
        return (new Route($methods, $uri, $action))
            ->setRouter($this)
            ->setContainer($this->container);
    }

    /**
     * Prefix the given URI with the last prefix.
     */
    protected function prefix(string $uri): string
    {
        return trim(trim($this->getLastGroupPrefix(), '/') . '/' . trim($uri, '/'), '/') ?: '/';
    }

    /**
     * Add the necessary where clauses to the route based on its initial registration.
     */
    protected function addWhereClausesToRoute(Route $route): Route
    {
        $route->where(array_merge(
            $this->patterns,
            $route->getAction()['where'] ?? []
        ));

        return $route;
    }

    /**
     * Merge the group stack with the controller action.
     */
    protected function mergeGroupAttributesIntoRoute(Route $route): void
    {
        $route->setAction($this->mergeWithLastGroup(
            $route->getAction(),
            prependExistingPrefix: false
        ));
    }

    /**
     * Return the response returned by the given route.
     */
    public function respondWithRoute(string $name): SymfonyResponse
    {
        $currentRequest = $this->getCurrentRequest();

        $route = tap($this->routes->getByName($name))->bind($currentRequest);

        return $this->runRoute($currentRequest, $route);
    }

    /**
     * Dispatch the request to the application.
     */
    public function dispatch(Request $request): SymfonyResponse
    {
        CoroutineContext::set(self::CURRENT_REQUEST_CONTEXT_KEY, $request);

        return $this->dispatchToRoute($request);
    }

    /**
     * Dispatch the request to a route and return the response.
     */
    public function dispatchToRoute(Request $request): SymfonyResponse
    {
        return $this->runRoute($request, $this->findRoute($request));
    }

    /**
     * Match a route, run middleware, and call the given callback instead of the controller.
     *
     * Performs the full route context lifecycle (route matching, context setup,
     * route resolver, RouteMatched event, middleware pipeline) but calls the
     * provided callback as the terminal handler instead of the route's action.
     *
     * Used by the WebSocket server for handshake requests where route matching
     * and middleware must run but the controller must not be invoked.
     */
    public function dispatchToCallback(Request $request, Closure $callback): SymfonyResponse
    {
        CoroutineContext::set(self::CURRENT_REQUEST_CONTEXT_KEY, $request);

        $route = $this->findRoute($request);

        $request->setRouteResolver(fn () => $route);

        if ($this->events->hasListeners(RouteMatched::class)) {
            $this->events->dispatch(new RouteMatched($route, $request));
        }

        $shouldSkipMiddleware = $this->container->bound('middleware.disable')
            && $this->container->make('middleware.disable') === true;

        $middleware = $shouldSkipMiddleware ? [] : $this->gatherRouteMiddleware($route);

        return $this->prepareResponse(
            $request,
            (new Pipeline($this->container))
                ->send($request)
                ->through($middleware)
                ->then($callback)
        );
    }

    /**
     * Find the route matching a given request.
     */
    protected function findRoute(Request $request): Route
    {
        if ($this->events->hasListeners(Routing::class)) {
            $this->events->dispatch(new Routing($request));
        }

        $route = $this->routes->match($request);

        CoroutineContext::set(self::CURRENT_ROUTE_CONTEXT_KEY, $route);

        $route->setContainer($this->container);

        return $route;
    }

    /**
     * Return the response for the given route.
     */
    protected function runRoute(Request $request, Route $route): SymfonyResponse
    {
        $request->setRouteResolver(fn () => $route);

        if ($this->events->hasListeners(RouteMatched::class)) {
            $this->events->dispatch(new RouteMatched($route, $request));
        }

        return $this->prepareResponse(
            $request,
            $this->runRouteWithinStack($route, $request)
        );
    }

    /**
     * Run the given route within a Stack "onion" instance.
     */
    protected function runRouteWithinStack(Route $route, Request $request): mixed
    {
        $shouldSkipMiddleware = $this->container->bound('middleware.disable')
                                && $this->container->make('middleware.disable') === true;

        $middleware = $shouldSkipMiddleware ? [] : $this->gatherRouteMiddleware($route);

        return (new Pipeline($this->container))
            ->send($request)
            ->through($middleware)
            ->then(fn ($request) => $this->prepareResponse(
                $request,
                $route->run()
            ));
    }

    /**
     * Gather the middleware for the given route with resolved class names.
     *
     * @return array<int, mixed>
     */
    public function gatherRouteMiddleware(Route $route): array
    {
        return $route->resolvedMiddleware ??= $this->resolveMiddleware(
            $route->gatherMiddleware(),
            $route->excludedMiddleware()
        );
    }

    /**
     * Resolve a flat array of middleware classes from the provided array.
     *
     * @return array<int, mixed>
     */
    public function resolveMiddleware(array $middleware, array $excluded = []): array
    {
        $excluded = $excluded === []
            ? $excluded
            : (new Collection($excluded))
                ->map(fn (string|Closure $name): string|Closure|array => MiddlewareNameResolver::resolve($name, $this->middleware, $this->middlewareGroups))
                ->flatten()
                ->values()
                ->all();

        $middleware = (new Collection($middleware))
            ->map(fn (string|Closure $name): string|Closure|array => MiddlewareNameResolver::resolve($name, $this->middleware, $this->middlewareGroups))
            ->flatten()
            ->when(
                ! empty($excluded),
                fn (Collection $collection): Collection => $collection->reject(function (mixed $name) use ($excluded): bool {
                    if ($name instanceof Closure) {
                        return false;
                    }

                    if (in_array($name, $excluded, true)) {
                        return true;
                    }

                    if (! class_exists($name)) {
                        return false;
                    }

                    $reflection = new ReflectionClass($name);

                    return (new Collection($excluded))->contains(
                        fn (string $exclude): bool => class_exists($exclude) && $reflection->isSubclassOf($exclude)
                    );
                })
            )
            ->values();

        return $this->sortMiddleware($middleware);
    }

    /**
     * Sort the given middleware by priority.
     *
     * @return array<int, mixed>
     */
    protected function sortMiddleware(Collection $middlewares): array
    {
        return (new SortedMiddleware($this->middlewarePriority, $middlewares))->all();
    }

    /**
     * Create a response instance from the given value.
     */
    public function prepareResponse(Request $request, mixed $response): SymfonyResponse
    {
        if ($this->events->hasListeners(PreparingResponse::class)) {
            $this->events->dispatch(new PreparingResponse($request, $response));
        }

        return tap(static::toResponse($request, $response), function (SymfonyResponse $response) use ($request): void {
            if ($this->events->hasListeners(ResponsePrepared::class)) {
                $this->events->dispatch(new ResponsePrepared($request, $response));
            }
        });
    }

    /**
     * Static version of prepareResponse.
     */
    public static function toResponse(Request $request, mixed $response): SymfonyResponse
    {
        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        if ($response instanceof PsrResponseInterface) {
            $response = (new HttpFoundationFactory())->createResponse($response);
        } elseif ($response instanceof Model && $response->wasRecentlyCreated) {
            $response = new JsonResponse($response, 201);
        } elseif ($response instanceof Stringable) {
            $response = new Response($response->__toString(), 200, ['Content-Type' => 'text/html']);
        } elseif (! $response instanceof SymfonyResponse
                   && ($response instanceof Arrayable
                    || $response instanceof Jsonable
                    || $response instanceof ArrayObject
                    || $response instanceof JsonSerializable
                    || $response instanceof stdClass
                    || is_array($response))) {
            $response = new JsonResponse($response);
        } elseif (! $response instanceof SymfonyResponse) {
            $response = new Response((string) $response, 200, ['Content-Type' => 'text/html']);
        }

        if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED) {
            $response->setNotModified();
        }

        return $response->prepare($request);
    }

    /**
     * Substitute the route bindings onto the route.
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<\Hypervel\Database\Eloquent\Model>
     * @throws \Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException
     */
    public function substituteBindings(Route $route): Route
    {
        foreach ($route->parameters() as $key => $value) {
            if (isset($this->binders[$key])) {
                $route->setParameter($key, $this->performBinding($key, $value, $route));
            }
        }

        return $route;
    }

    /**
     * Substitute the implicit route bindings for the given route.
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<\Hypervel\Database\Eloquent\Model>
     * @throws \Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException
     */
    public function substituteImplicitBindings(Route $route): mixed
    {
        $default = fn () => ImplicitRouteBinding::resolveForRoute($this->container, $route);

        return call_user_func(
            $this->implicitBindingCallback ?? $default,
            $this->container,
            $route,
            $default
        );
    }

    /**
     * Register a callback to run after implicit bindings are substituted.
     *
     * @return $this
     */
    public function substituteImplicitBindingsUsing(callable $callback): static
    {
        $this->implicitBindingCallback = $callback;

        return $this;
    }

    /**
     * Call the binding callback for the given key.
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<\Hypervel\Database\Eloquent\Model>
     */
    protected function performBinding(string $key, string $value, Route $route): mixed
    {
        return call_user_func($this->binders[$key], $value, $route);
    }

    /**
     * Register a route matched event listener.
     */
    public function matched(string|callable $callback): void
    {
        $this->events->listen(Events\RouteMatched::class, $callback);
    }

    /**
     * Get all of the defined middleware short-hand names.
     *
     * @return array<string, string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Register a short-hand name for a middleware.
     *
     * @return $this
     */
    public function aliasMiddleware(string $name, string|Closure $class): static
    {
        $this->middleware[$name] = $class;

        return $this;
    }

    /**
     * Check if a middlewareGroup with the given name exists.
     */
    public function hasMiddlewareGroup(string $name): bool
    {
        return array_key_exists($name, $this->middlewareGroups);
    }

    /**
     * Get all of the defined middleware groups.
     *
     * @return array<string, array<int, string>>
     */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }

    /**
     * Register a group of middleware.
     *
     * @return $this
     */
    public function middlewareGroup(string $name, array $middleware): static
    {
        $this->middlewareGroups[$name] = $middleware;

        return $this;
    }

    /**
     * Add a middleware to the beginning of a middleware group.
     *
     * If the middleware is already in the group, it will not be added again.
     *
     * @return $this
     */
    public function prependMiddlewareToGroup(string $group, string $middleware): static
    {
        if (isset($this->middlewareGroups[$group]) && ! in_array($middleware, $this->middlewareGroups[$group], true)) {
            array_unshift($this->middlewareGroups[$group], $middleware);
        }

        return $this;
    }

    /**
     * Add a middleware to the end of a middleware group.
     *
     * If the middleware is already in the group, it will not be added again.
     *
     * @return $this
     */
    public function pushMiddlewareToGroup(string $group, string $middleware): static
    {
        if (! array_key_exists($group, $this->middlewareGroups)) {
            $this->middlewareGroups[$group] = [];
        }

        if (! in_array($middleware, $this->middlewareGroups[$group], true)) {
            $this->middlewareGroups[$group][] = $middleware;
        }

        return $this;
    }

    /**
     * Remove the given middleware from the specified group.
     *
     * @return $this
     */
    public function removeMiddlewareFromGroup(string $group, string|array $middleware): static
    {
        if (! $this->hasMiddlewareGroup($group)) {
            return $this;
        }

        foreach ((array) $middleware as $item) {
            $reversedMiddlewaresArray = array_flip($this->middlewareGroups[$group]);

            if (! array_key_exists($item, $reversedMiddlewaresArray)) {
                continue;
            }

            $middlewareKey = $reversedMiddlewaresArray[$item];

            unset($this->middlewareGroups[$group][$middlewareKey]);
        }

        return $this;
    }

    /**
     * Flush the router's middleware groups.
     *
     * @return $this
     */
    public function flushMiddlewareGroups(): static
    {
        $this->middlewareGroups = [];

        return $this;
    }

    /**
     * Add a new route parameter binder.
     */
    public function bind(string $key, string|callable $binder): void
    {
        $this->binders[str_replace('-', '_', $key)] = RouteBinding::forCallback(
            $this->container,
            $binder
        );
    }

    /**
     * Register a model binder for a wildcard.
     */
    public function model(string $key, string $class, ?Closure $callback = null): void
    {
        $this->bind($key, RouteBinding::forModel($this->container, $class, $callback));
    }

    /**
     * Get the binding callback for a given binding.
     */
    public function getBindingCallback(string $key): ?Closure
    {
        if (isset($this->binders[$key = str_replace('-', '_', $key)])) {
            return $this->binders[$key];
        }

        return null;
    }

    /**
     * Get the global "where" patterns.
     *
     * @return array<string, string>
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Set a global where pattern on all routes.
     */
    public function pattern(string $key, string $pattern): void
    {
        $this->patterns[$key] = $pattern;
    }

    /**
     * Set a group of global where patterns on all routes.
     */
    public function patterns(array $patterns): void
    {
        foreach ($patterns as $key => $pattern) {
            $this->pattern($key, $pattern);
        }
    }

    /**
     * Determine if the router currently has a group stack.
     */
    public function hasGroupStack(): bool
    {
        return ! empty($this->groupStack);
    }

    /**
     * Get the current group stack for the router.
     */
    public function getGroupStack(): array
    {
        return $this->groupStack;
    }

    /**
     * Get a route parameter for the current route.
     */
    public function input(string $key, ?string $default = null): mixed
    {
        return $this->current()->parameter($key, $default);
    }

    /**
     * Get the request currently being dispatched.
     */
    public function getCurrentRequest(): ?Request
    {
        return CoroutineContext::get(self::CURRENT_REQUEST_CONTEXT_KEY);
    }

    /**
     * Get the currently dispatched route instance.
     */
    public function getCurrentRoute(): ?Route
    {
        return $this->current();
    }

    /**
     * Get the currently dispatched route instance.
     */
    public function current(): ?Route
    {
        return CoroutineContext::get(self::CURRENT_ROUTE_CONTEXT_KEY);
    }

    /**
     * Check if a route with the given name exists.
     */
    public function has(string|array $name): bool
    {
        $names = is_array($name) ? $name : func_get_args();

        foreach ($names as $value) {
            if (! $this->routes->hasNamedRoute($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the current route name.
     */
    public function currentRouteName(): ?string
    {
        return $this->current() ? $this->current()->getName() : null;
    }

    /**
     * Alias for the "currentRouteNamed" method.
     */
    public function is(mixed ...$patterns): bool
    {
        return $this->currentRouteNamed(...$patterns);
    }

    /**
     * Determine if the current route matches a pattern.
     */
    public function currentRouteNamed(mixed ...$patterns): bool
    {
        return $this->current() && $this->current()->named(...$patterns);
    }

    /**
     * Get the current route action.
     */
    public function currentRouteAction(): ?string
    {
        if ($this->current()) {
            return $this->current()->getAction()['controller'] ?? null;
        }

        return null;
    }

    /**
     * Alias for the "currentRouteUses" method.
     */
    public function uses(array|string ...$patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $this->currentRouteAction())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the current route action matches a given action.
     */
    public function currentRouteUses(string $action): bool
    {
        return $this->currentRouteAction() === $action;
    }

    /**
     * Set the unmapped global resource parameters to singular.
     */
    public function singularResourceParameters(bool $singular = true): void
    {
        ResourceRegistrar::singularParameters($singular);
    }

    /**
     * Set the global resource parameter mapping.
     */
    public function resourceParameters(array $parameters = []): void
    {
        ResourceRegistrar::setParameters($parameters);
    }

    /**
     * Get or set the verbs used in the resource URIs.
     */
    public function resourceVerbs(array $verbs = []): ?array
    {
        return ResourceRegistrar::verbs($verbs);
    }

    /**
     * Get the underlying route collection.
     */
    public function getRoutes(): RouteCollectionInterface
    {
        return $this->routes;
    }

    /**
     * Set the route collection instance.
     */
    public function setRoutes(RouteCollection $routes): void
    {
        $this->flushRoutingCaches();

        foreach ($routes as $route) {
            $route->setRouter($this)->setContainer($this->container);
        }

        $this->routes = $routes;

        $this->container->instance('routes', $this->routes);
    }

    /**
     * Set the compiled route collection instance.
     */
    public function setCompiledRoutes(array $routes): void
    {
        $this->flushRoutingCaches();

        $this->routes = (new CompiledRouteCollection($routes['compiled'], $routes['attributes']))
            ->setRouter($this)
            ->setContainer($this->container);

        $this->container->instance('routes', $this->routes);
    }

    /**
     * Flush all static routing caches.
     *
     * Called when the route collection is replaced to clear stale data.
     * In Swoole workers live forever, so stale statics would persist.
     */
    protected function flushRoutingCaches(): void
    {
        CompiledRouteCollection::flushCache();
        ControllerDispatcher::flushState();
        CallableDispatcher::flushState();
        RouteSignatureParameters::flushCache();
        SortedMiddleware::flushCache();
        ImplicitRouteBinding::flushCache();
    }

    /**
     * Compile routes and pre-warm all static caches.
     *
     * In dev mode, compiles RouteCollection → CompiledRouteCollection for
     * CompiledUrlMatcher performance (O(1) hash + single regex). In production
     * with route:cache, routes are already CompiledRouteCollection — compile
     * is skipped and only warmUp() runs.
     *
     * Called from HttpServer\Server and WebSocketServer\Server after
     * Kernel::bootstrap() returns, before $server->start(). Idempotent —
     * safe to call from both servers in combined setups.
     */
    public function compileAndWarm(): void
    {
        $routes = $this->getRoutes();

        if ($routes instanceof RouteCollection) {
            $this->setCompiledRoutes($routes->compile());
        }

        $this->warmUp();
    }

    /**
     * Pre-warm all static caches for the registered routes.
     *
     * Eagerly populates route compilation, middleware resolution, and
     * reflection caches so they're available before fork. Workers inherit
     * via copy-on-write in both SWOOLE_PROCESS and SWOOLE_BASE modes.
     */
    public function warmUp(): void
    {
        foreach ($this->getRoutes()->getWarmableRoutes() as $route) {
            // 1. Compile each route's regex (populates Route::$compiled)
            $route->ensureCompiled();

            // Use public getControllerClass() — isControllerAction() is protected.
            // Returns null for non-controller routes (closures, invokable objects, etc.)
            // Normalize with ltrim() because route registration may leave a leading \
            // (e.g. '\App\Http\Controllers\Foo'), but get_class() at runtime never does.
            // Without this, warm cache keys wouldn't match runtime cache keys.
            $class = $route->getControllerClass();

            if ($class !== null) {
                $class = ltrim($class, '\\');
            }

            // 2. Pre-warm middleware (populates Route::$computedMiddleware)
            // Safe for HasMiddleware (static method) and attribute-based middleware
            // (pure reflection). The only unsafe path is legacy getMiddleware() which
            // calls getController() → CoroutineContext::getOrSet() — no coroutine at boot.
            if ($class !== null
                && (is_a($class, \Hypervel\Routing\Controllers\HasMiddleware::class, true)
                    || ! method_exists($class, 'getMiddleware'))
            ) {
                $route->gatherMiddleware();
            }

            // 3. Pre-warm RouteSignatureParameters cache for controller actions.
            // Only controller routes (string 'Class@method') — RouteSignatureParameters::fromAction()
            // uses ReflectionFunction internally which fails for array callables and
            // invokable objects.
            if ($class !== null) {
                RouteSignatureParameters::fromAction($route->getAction());
            }

            // 4. Pre-warm ControllerDispatcher reflection cache for controller actions
            if ($class !== null) {
                $method = ltrim(strrchr($route->getAction('uses'), '@') ?: '', '@');

                if ($method !== '' && method_exists($class, $method)) {
                    ControllerDispatcher::warmReflection($class, $method);
                }
            }
        }
    }

    /**
     * Remove any duplicate middleware from the given array.
     */
    public static function uniqueMiddleware(array $middleware): array
    {
        $seen = [];
        $result = [];

        foreach ($middleware as $value) {
            $key = is_object($value) ? spl_object_id($value) : $value;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Set the container instance used by the router.
     *
     * @return $this
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Dynamically handle calls into the router instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if ($method === 'middleware') {
            return (new RouteRegistrar($this))->attribute($method, is_array($parameters[0]) ? $parameters[0] : $parameters);
        }

        if ($method === 'can') {
            return (new RouteRegistrar($this))->attribute($method, [$parameters]);
        }

        if ($method !== 'where' && Str::startsWith($method, 'where')) {
            return (new RouteRegistrar($this))->{$method}(...$parameters);
        }

        return (new RouteRegistrar($this))->attribute($method, array_key_exists(0, $parameters) ? $parameters[0] : true);
    }
}
