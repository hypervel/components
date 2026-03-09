<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use BackedEnum;
use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Request;
use Hypervel\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Hypervel\Routing\Contracts\CallableDispatcher;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Routing\Controllers\HasMiddleware;
use Hypervel\Routing\Controllers\Middleware;
use Hypervel\Routing\Matching\HostValidator;
use Hypervel\Routing\Matching\MethodValidator;
use Hypervel\Routing\Matching\SchemeValidator;
use Hypervel\Routing\Matching\UriValidator;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Stringable;
use Symfony\Component\Routing\CompiledRoute;
use Symfony\Component\Routing\Route as SymfonyRoute;
use UnexpectedValueException;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Route
{
    use Conditionable;
    use CreatesRegularExpressionRouteConstraints;
    use FiltersControllerMiddleware;
    use Macroable;
    use ResolvesRouteDependencies;

    /**
     * Context key for coroutine-local route parameters.
     *
     * Route objects are cached and shared across coroutines, so per-request
     * mutable state (parameters, controller instances) must be stored in
     * coroutine-local Context rather than on the Route instance.
     */
    private const PARAMS_CONTEXT_KEY = '__routing.parameters';

    /**
     * Context key for coroutine-local original route parameters.
     */
    private const ORIGINAL_PARAMS_CONTEXT_KEY = '__routing.original_parameters';

    /**
     * The URI pattern the route responds to.
     */
    public string $uri;

    /**
     * The HTTP methods the route responds to.
     */
    public array $methods;

    /**
     * The route action array.
     */
    public array $action;

    /**
     * Indicates whether the route is a fallback route.
     */
    public bool $isFallback = false;

    /**
     * The default values for the route.
     */
    public array $defaults = [];

    /**
     * The regular expression requirements.
     */
    public array $wheres = [];

    /**
     * The parameter names for the route.
     */
    public ?array $parameterNames = null;

    /**
     * Indicates "trashed" models can be retrieved when resolving implicit model bindings for this route.
     */
    protected bool $withTrashedBindings = false;

    /**
     * Indicates the maximum number of seconds the route should acquire a session lock for.
     */
    protected ?int $lockSeconds = null;

    /**
     * Indicates the maximum number of seconds the route should wait while attempting to acquire a session lock.
     */
    protected ?int $waitSeconds = null;

    /**
     * The computed gathered middleware.
     *
     * Safe to cache on the Route instance — middleware is deterministic
     * per route (same route always produces the same middleware list).
     */
    public ?array $computedMiddleware = null;

    /**
     * The cached resolved middleware with class names expanded and groups resolved.
     *
     * Computed by Router::gatherRouteMiddleware() from the $computedMiddleware
     * list. Cached here because the router's middleware aliases and groups are
     * stable after boot in Swoole workers.
     */
    public ?array $resolvedMiddleware = null;

    /**
     * The compiled version of the route.
     *
     * Safe to cache on the Route instance — immutable after compilation,
     * persists for the worker lifetime.
     */
    public ?CompiledRoute $compiled = null;

    /**
     * The resolved callable for the route.
     *
     * Cached to avoid repeated unserialize() calls for serialized closures
     * (route caching). The resolved callable is a deterministic transformation
     * of the immutable serialized string in the action array.
     */
    protected ?Closure $callable = null;

    /**
     * The resolved missing model handler for the route.
     *
     * Same caching rationale as $callable — avoids repeated unserialize().
     */
    protected ?Closure $missing = null;

    /**
     * The cached callable dispatcher instance.
     */
    protected ?CallableDispatcher $callableDispatcher = null;

    /**
     * The cached controller instance for worker-shared (singleton) controllers.
     */
    protected mixed $controller = null;

    /**
     * Whether this route's controller may be cached on the shared Route instance.
     */
    private ?bool $shouldCacheControllerOnRoute = null;

    /**
     * The cached controller dispatcher instance.
     */
    protected ?ControllerDispatcherContract $controllerDispatcher = null;

    /**
     * The router instance used by the route.
     */
    protected ?Router $router = null;

    /**
     * The container instance used by the route.
     */
    protected ?Container $container = null;

    /**
     * The fields that implicit binding should use for a given parameter.
     */
    protected array $bindingFields = [];

    /**
     * The validators used by the routes.
     *
     * @var null|array<int, \Hypervel\Routing\Matching\ValidatorInterface>
     */
    public static ?array $validators = null;

    /**
     * Create a new Route instance.
     */
    public function __construct(array|string $methods, string $uri, Closure|array|null $action)
    {
        $this->uri = $uri;
        $this->methods = (array) $methods;
        $this->action = Arr::except($this->parseAction($action), ['prefix']);

        if (in_array('GET', $this->methods) && ! in_array('HEAD', $this->methods)) {
            $this->methods[] = 'HEAD';
        }

        $this->prefix(is_array($action) ? Arr::get($action, 'prefix') : '');
    }

    /**
     * Parse the route action into a standard array.
     *
     * @throws UnexpectedValueException
     */
    protected function parseAction(callable|array|null $action): array
    {
        return RouteAction::parse($this->uri, $action);
    }

    /**
     * Run the route action and return the response.
     */
    public function run(): mixed
    {
        $this->container = $this->container ?: new Container();

        try {
            if ($this->isControllerAction()) {
                return $this->runController();
            }

            return $this->runCallable();
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }
    }

    /**
     * Check whether the route's action is a controller.
     */
    protected function isControllerAction(): bool
    {
        return is_string($this->action['uses']) && ! $this->isSerializedClosure();
    }

    /**
     * Run the callable route action and return the response.
     */
    protected function runCallable(): mixed
    {
        if (! $this->callable) {
            $this->callable = $this->isSerializedClosure()
                ? unserialize($this->action['uses'])->getClosure()
                : $this->action['uses'];
        }

        return $this->callableDispatcher()->dispatch($this, $this->callable);
    }

    /**
     * Get the callable dispatcher for the route.
     */
    protected function callableDispatcher(): CallableDispatcher
    {
        return $this->callableDispatcher ??= $this->container->make(CallableDispatcher::class);
    }

    /**
     * Determine if the route action is a serialized Closure.
     */
    protected function isSerializedClosure(): bool
    {
        return RouteAction::containsSerializedClosure($this->action);
    }

    /**
     * Run the controller route action and return the response.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    protected function runController(): mixed
    {
        return $this->controllerDispatcher()->dispatch(
            $this,
            $this->getController(),
            $this->getControllerMethod()
        );
    }

    /**
     * Get the controller instance for the route.
     *
     * For worker-shared controllers (singletons and unbound auto-singletons),
     * the instance is cached directly on the Route property for zero-overhead
     * access across coroutines.
     *
     * For controllers with per-coroutine semantics (#[Scoped] or explicit
     * bind()), the instance is stored in coroutine Context to preserve the
     * container's binding contract. Context::getOrSet memoizes within a
     * coroutine (getController() is called twice — middleware + dispatch)
     * while still giving each coroutine its own instance.
     */
    public function getController(): mixed
    {
        if (! $this->isControllerAction()) {
            return null;
        }

        $class = ltrim((string) $this->getControllerClass(), '\\');

        if ($this->shouldCacheControllerOnRoute($class)) {
            return $this->controller ??= $this->container->make($class);
        }

        return Context::getOrSet(
            '__routing.controller.' . $class,
            fn () => $this->container->make($class)
        );
    }

    /**
     * Determine if this route's controller may be cached on the shared Route instance.
     *
     * Worker-shared bindings (singletons, auto-singletons) are safe to cache
     * on the Route property. Per-coroutine bindings (#[Scoped], explicit bind())
     * must go through Context to preserve the container's binding contract.
     */
    private function shouldCacheControllerOnRoute(string $class): bool
    {
        return $this->shouldCacheControllerOnRoute ??= match (true) {
            $this->container->isScoped($class) => false,
            $this->container->bound($class) => $this->container->isShared($class),
            default => true,
        };
    }

    /**
     * Get the controller class used for the route.
     */
    public function getControllerClass(): ?string
    {
        return $this->isControllerAction() ? $this->parseControllerCallback()[0] : null;
    }

    /**
     * Get the controller method used for the route.
     */
    protected function getControllerMethod(): string
    {
        return $this->parseControllerCallback()[1];
    }

    /**
     * Parse the controller.
     */
    protected function parseControllerCallback(): array
    {
        return Str::parseCallback($this->action['uses']);
    }

    /**
     * Flush the cached controller state on the route.
     *
     * Clears both the route-level property cache (for worker-shared controllers)
     * and the coroutine Context entry (for scoped/bound controllers), plus the
     * container's auto-singleton cache so make() creates a fresh instance.
     */
    public function flushController(): void
    {
        $this->computedMiddleware = null;
        $this->resolvedMiddleware = null;
        $this->controller = null;

        if ($this->isControllerAction()) {
            $class = ltrim((string) $this->getControllerClass(), '\\');
            Context::destroy('__routing.controller.' . $class);
            $this->container?->forgetInstance($class);
        }
    }

    /**
     * Determine if the route matches a given request.
     */
    public function matches(Request $request, bool $includingMethod = true): bool
    {
        $this->compileRoute();

        foreach (self::getValidators() as $validator) {
            if (! $includingMethod && $validator instanceof MethodValidator) {
                continue;
            }

            if (! $validator->matches($this, $request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compile the route into a Symfony CompiledRoute instance.
     *
     * The compiled result is cached on the Route instance for the worker
     * lifetime — it's immutable after compilation and safe to share.
     */
    protected function compileRoute(): CompiledRoute
    {
        if (! $this->compiled) {
            $this->compiled = $this->toSymfonyRoute()->compile();
        }

        return $this->compiled;
    }

    /**
     * Ensure the route is compiled.
     *
     * Called during server boot pre-warming to populate the compiled regex
     * cache before fork, so workers inherit it via copy-on-write.
     */
    public function ensureCompiled(): void
    {
        $this->compileRoute();
    }

    /**
     * Bind the route to a given request for execution.
     *
     * Parameters are stored in coroutine Context, not on the Route instance,
     * because Route objects are cached and shared across coroutines.
     */
    public function bind(Request $request): static
    {
        $this->compileRoute();

        $parameters = (new RouteParameterBinder($this))->parameters($request);

        Context::set(self::PARAMS_CONTEXT_KEY, $parameters);
        Context::set(self::ORIGINAL_PARAMS_CONTEXT_KEY, $parameters);

        return $this;
    }

    /**
     * Determine if the route has parameters.
     */
    public function hasParameters(): bool
    {
        return Context::has(self::PARAMS_CONTEXT_KEY);
    }

    /**
     * Determine if a given parameter exists on the route.
     */
    public function hasParameter(string $name): bool
    {
        if ($this->hasParameters()) {
            return array_key_exists($name, $this->parameters());
        }

        return false;
    }

    /**
     * Get a given parameter from the route.
     */
    public function parameter(string $name, mixed $default = null): mixed
    {
        return Arr::get($this->parameters(), $name, $default);
    }

    /**
     * Get original value of a given parameter from the route.
     */
    public function originalParameter(string $name, ?string $default = null): ?string
    {
        return Arr::get($this->originalParameters(), $name, $default);
    }

    /**
     * Set a parameter to the given value.
     */
    public function setParameter(string $name, mixed $value): void
    {
        $this->parameters();

        $parameters = Context::get(self::PARAMS_CONTEXT_KEY, []);
        $parameters[$name] = $value;
        Context::set(self::PARAMS_CONTEXT_KEY, $parameters);
    }

    /**
     * Unset a parameter on the route if it is set.
     */
    public function forgetParameter(string $name): void
    {
        $this->parameters();

        $parameters = Context::get(self::PARAMS_CONTEXT_KEY, []);
        unset($parameters[$name]);
        Context::set(self::PARAMS_CONTEXT_KEY, $parameters);
    }

    /**
     * Get the key / value list of parameters for the route.
     *
     * @throws LogicException
     */
    public function parameters(): array
    {
        if (Context::has(self::PARAMS_CONTEXT_KEY)) {
            return Context::get(self::PARAMS_CONTEXT_KEY);
        }

        throw new LogicException('Route is not bound.');
    }

    /**
     * Get the key / value list of original parameters for the route.
     *
     * @throws LogicException
     */
    public function originalParameters(): array
    {
        if (Context::has(self::ORIGINAL_PARAMS_CONTEXT_KEY)) {
            return Context::get(self::ORIGINAL_PARAMS_CONTEXT_KEY);
        }

        throw new LogicException('Route is not bound.');
    }

    /**
     * Get the key / value list of parameters without null values.
     */
    public function parametersWithoutNulls(): array
    {
        return array_filter($this->parameters(), fn ($parameter) => ! is_null($parameter));
    }

    /**
     * Get all of the parameter names for the route.
     */
    public function parameterNames(): array
    {
        if (isset($this->parameterNames)) {
            return $this->parameterNames;
        }

        return $this->parameterNames = $this->compileParameterNames();
    }

    /**
     * Get the parameter names for the route.
     */
    protected function compileParameterNames(): array
    {
        preg_match_all('/\{(.*?)\}/', $this->getDomain() . $this->uri, $matches);

        return array_map(fn ($match) => trim($match, '?'), $matches[1]);
    }

    /**
     * Get the parameters that are listed in the route / controller signature.
     */
    public function signatureParameters(array|string $conditions = []): array
    {
        if (is_string($conditions)) {
            $conditions = ['subClass' => $conditions];
        }

        return RouteSignatureParameters::fromAction($this->action, $conditions);
    }

    /**
     * Get the binding field for the given parameter.
     */
    public function bindingFieldFor(string|int $parameter): ?string
    {
        $fields = is_int($parameter) ? array_values($this->bindingFields) : $this->bindingFields;

        return $fields[$parameter] ?? null;
    }

    /**
     * Get the binding fields for the route.
     */
    public function bindingFields(): array
    {
        return $this->bindingFields;
    }

    /**
     * Set the binding fields for the route.
     */
    public function setBindingFields(array $bindingFields): static
    {
        $this->bindingFields = $bindingFields;

        return $this;
    }

    /**
     * Get the parent parameter of the given parameter.
     */
    public function parentOfParameter(string $parameter): mixed
    {
        $parameters = $this->parameters();
        $key = array_search($parameter, array_keys($parameters));

        if ($key === 0 || $key === false) {
            return null;
        }

        return array_values($parameters)[$key - 1];
    }

    /**
     * Allow "trashed" models to be retrieved when resolving implicit model bindings for this route.
     */
    public function withTrashed(bool $withTrashed = true): static
    {
        $this->withTrashedBindings = $withTrashed;

        return $this;
    }

    /**
     * Determine if the route allows "trashed" models to be retrieved when resolving implicit model bindings.
     */
    public function allowsTrashedBindings(): bool
    {
        return $this->withTrashedBindings;
    }

    /**
     * Set a default value for the route.
     */
    public function defaults(string $key, mixed $value): static
    {
        $this->defaults[$key] = $value;

        return $this;
    }

    /**
     * Set the default values for the route.
     */
    public function setDefaults(array $defaults): static
    {
        $this->defaults = $defaults;

        return $this;
    }

    /**
     * Set a regular expression requirement on the route.
     */
    public function where(array|string $name, ?string $expression = null): static
    {
        foreach ($this->parseWhere($name, $expression) as $name => $expression) {
            $this->wheres[$name] = $expression;
        }

        return $this;
    }

    /**
     * Parse arguments to the where method into an array.
     */
    protected function parseWhere(array|string $name, ?string $expression): array
    {
        return is_array($name) ? $name : [$name => $expression];
    }

    /**
     * Set a list of regular expression requirements on the route.
     */
    public function setWheres(array $wheres): static
    {
        foreach ($wheres as $name => $expression) {
            $this->where($name, $expression);
        }

        return $this;
    }

    /**
     * Mark this route as a fallback route.
     */
    public function fallback(): static
    {
        $this->isFallback = true;

        return $this;
    }

    /**
     * Set the fallback value.
     */
    public function setFallback(bool $isFallback): static
    {
        $this->isFallback = $isFallback;

        return $this;
    }

    /**
     * Get the HTTP verbs the route responds to.
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * Determine if the route only responds to HTTP requests.
     */
    public function httpOnly(): bool
    {
        return in_array('http', $this->action, true);
    }

    /**
     * Determine if the route only responds to HTTPS requests.
     */
    public function httpsOnly(): bool
    {
        return $this->secure();
    }

    /**
     * Determine if the route only responds to HTTPS requests.
     */
    public function secure(): bool
    {
        return in_array('https', $this->action, true);
    }

    /**
     * Get or set the domain for the route.
     *
     * @return null|$this|string
     *
     * @throws InvalidArgumentException
     */
    public function domain(BackedEnum|string|null $domain = null): static|string|null
    {
        if (is_null($domain)) {
            return $this->getDomain();
        }

        if ($domain instanceof BackedEnum && ! is_string($domain = $domain->value)) {
            throw new InvalidArgumentException('Enum must be string backed.');
        }

        $parsed = RouteUri::parse($domain);

        $this->action['domain'] = $parsed->uri;

        $this->bindingFields = array_merge(
            $this->bindingFields,
            $parsed->bindingFields
        );

        return $this;
    }

    /**
     * Get the domain defined for the route.
     */
    public function getDomain(): ?string
    {
        return isset($this->action['domain'])
            ? str_replace(['http://', 'https://'], '', $this->action['domain'])
            : null;
    }

    /**
     * Get the prefix of the route instance.
     */
    public function getPrefix(): ?string
    {
        return $this->action['prefix'] ?? null;
    }

    /**
     * Add a prefix to the route URI.
     */
    public function prefix(?string $prefix): static
    {
        $prefix ??= '';

        $this->updatePrefixOnAction($prefix);

        $uri = rtrim($prefix, '/') . '/' . ltrim($this->uri, '/');

        return $this->setUri($uri !== '/' ? trim($uri, '/') : $uri);
    }

    /**
     * Update the "prefix" attribute on the action array.
     */
    protected function updatePrefixOnAction(string $prefix): void
    {
        if (! empty($newPrefix = trim(rtrim($prefix, '/') . '/' . ltrim($this->action['prefix'] ?? '', '/'), '/'))) {
            $this->action['prefix'] = $newPrefix;
        }
    }

    /**
     * Get the URI associated with the route.
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Set the URI that the route responds to.
     */
    public function setUri(string $uri): static
    {
        $this->uri = $this->parseUri($uri);

        return $this;
    }

    /**
     * Parse the route URI and normalize / store any implicit binding fields.
     */
    protected function parseUri(string $uri): string
    {
        $this->bindingFields = [];

        return tap(RouteUri::parse($uri), function ($uri) {
            $this->bindingFields = $uri->bindingFields;
        })->uri;
    }

    /**
     * Get the name of the route instance.
     */
    public function getName(): ?string
    {
        return $this->action['as'] ?? null;
    }

    /**
     * Add or change the route name.
     *
     * @throws InvalidArgumentException
     */
    public function name(BackedEnum|string $name): static
    {
        if ($name instanceof BackedEnum && ! is_string($name = $name->value)) {
            throw new InvalidArgumentException('Enum must be string backed.');
        }

        $this->action['as'] = isset($this->action['as']) ? $this->action['as'] . $name : $name;

        return $this;
    }

    /**
     * Determine whether the route's name matches the given patterns.
     */
    public function named(mixed ...$patterns): bool
    {
        if (is_null($routeName = $this->getName())) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the handler for the route.
     */
    public function uses(Closure|array|string $action): static
    {
        if (is_array($action)) {
            $action = $action[0] . '@' . $action[1];
        }

        $action = is_string($action) ? $this->addGroupNamespaceToStringUses($action) : $action;

        return $this->setAction(array_merge($this->action, $this->parseAction([
            'uses' => $action,
            'controller' => $action,
        ])));
    }

    /**
     * Parse a string based action for the "uses" fluent method.
     */
    protected function addGroupNamespaceToStringUses(string $action): string
    {
        $groupStack = last($this->router->getGroupStack());

        if (isset($groupStack['namespace']) && ! str_starts_with($action, '\\')) {
            return $groupStack['namespace'] . '\\' . $action;
        }

        return $action;
    }

    /**
     * Get the action name for the route.
     */
    public function getActionName(): string
    {
        return $this->action['controller'] ?? 'Closure';
    }

    /**
     * Get the method name of the route action.
     */
    public function getActionMethod(): string
    {
        return array_last(explode('@', $this->getActionName()));
    }

    /**
     * Get the action array or one of its properties for the route.
     */
    public function getAction(?string $key = null): mixed
    {
        return Arr::get($this->action, $key);
    }

    /**
     * Set the action array for the route.
     */
    public function setAction(array $action): static
    {
        $this->action = $action;
        $this->callable = null;
        $this->missing = null;

        if (isset($this->action['domain'])) {
            $this->domain($this->action['domain']);
        }

        if (isset($this->action['can'])) {
            foreach ($this->action['can'] as $can) {
                $this->can($can[0], $can[1] ?? []);
            }
        }

        return $this;
    }

    /**
     * Get the value of the action that should be taken on a missing model exception.
     */
    public function getMissing(): ?Closure
    {
        if ($this->missing) {
            return $this->missing;
        }

        $missing = $this->action['missing'] ?? null;

        if (is_string($missing)
            && Str::startsWith($missing, [
                'O:47:"Laravel\SerializableClosure\SerializableClosure',
                'O:55:"Laravel\SerializableClosure\UnsignedSerializableClosure',
            ])) {
            return $this->missing = unserialize($missing)->getClosure();
        }

        return $missing;
    }

    /**
     * Define the callable that should be invoked on a missing model exception.
     */
    public function missing(Closure $missing): static
    {
        $this->action['missing'] = $missing;
        $this->missing = null;

        return $this;
    }

    /**
     * Get all middleware, including the ones from the controller.
     */
    public function gatherMiddleware(): array
    {
        if (! is_null($this->computedMiddleware)) {
            return $this->computedMiddleware;
        }

        $this->computedMiddleware = [];

        return $this->computedMiddleware = Router::uniqueMiddleware(array_merge(
            $this->middleware(),
            $this->controllerMiddleware()
        ));
    }

    /**
     * Get or set the middlewares attached to the route.
     *
     * @return ($middleware is null ? array : $this)
     */
    public function middleware(array|string|Stringable|null $middleware = null): static|array
    {
        if (is_null($middleware)) {
            return (array) ($this->action['middleware'] ?? []);
        }

        if (! is_array($middleware)) {
            $middleware = func_get_args();
        }

        foreach ($middleware as $index => $value) {
            $middleware[$index] = (string) $value;
        }

        $this->action['middleware'] = array_merge(
            (array) ($this->action['middleware'] ?? []),
            $middleware
        );

        return $this;
    }

    /**
     * Specify that the "Authorize" / "can" middleware should be applied to the route.
     */
    public function can(UnitEnum|string $ability, array|string $models = []): static
    {
        $ability = enum_value($ability);

        return empty($models)
            ? $this->middleware(['can:' . $ability])
            : $this->middleware(['can:' . $ability . ',' . implode(',', Arr::wrap($models))]);
    }

    /**
     * Get the middleware for the route's controller.
     */
    public function controllerMiddleware(): array
    {
        if (! $this->isControllerAction()) {
            return [];
        }

        [$controllerClass, $controllerMethod] = [
            $this->getControllerClass(),
            $this->getControllerMethod(),
        ];

        if (is_a($controllerClass, HasMiddleware::class, true)) {
            return $this->staticallyProvidedControllerMiddleware(
                $controllerClass,
                $controllerMethod
            );
        }

        if (method_exists($controllerClass, 'getMiddleware')) {
            return $this->controllerDispatcher()->getMiddleware(
                $this->getController(),
                $controllerMethod
            );
        }

        return $this->attributeProvidedControllerMiddleware(
            $controllerClass,
            $controllerMethod
        );
    }

    /**
     * Get the statically provided controller middleware for the given class and method.
     */
    protected function staticallyProvidedControllerMiddleware(string $class, string $method): array
    {
        return (new Collection($class::middleware()))
            ->map(function ($middleware) {
                return $middleware instanceof Middleware
                    ? $middleware
                    : new Middleware($middleware);
            })
            ->reject(function ($middleware) use ($method) {
                return static::methodExcludedByOptions(
                    $method,
                    ['only' => $middleware->only, 'except' => $middleware->except],
                );
            })
            ->map
            ->middleware
            ->flatten() // @phpstan-ignore method.nonObject (HigherOrderCollectionProxy result can't be statically typed)
            ->values()
            ->all();
    }

    /**
     * Get the attribute provided controller middleware for the given class and method.
     */
    protected function attributeProvidedControllerMiddleware(string $class, string $method): array
    {
        try {
            $reflectionClass = new ReflectionClass($class);

            $reflectionMethod = $reflectionClass->getMethod($method);
        } catch (ReflectionException) {
            return [];
        }

        return (new Collection(array_merge(
            $reflectionClass->getAttributes(MiddlewareAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
            $reflectionMethod->getAttributes(MiddlewareAttribute::class, ReflectionAttribute::IS_INSTANCEOF),
        )))->map(function (ReflectionAttribute $attribute) use ($method) {
            $instance = $attribute->newInstance();

            return static::methodExcludedByOptions(
                $method,
                ['only' => $instance->only, 'except' => $instance->except],
            ) ? null : $instance->value;
        })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Specify middleware that should be removed from the given route.
     */
    public function withoutMiddleware(array|string $middleware): static
    {
        $this->action['excluded_middleware'] = array_merge(
            (array) ($this->action['excluded_middleware'] ?? []),
            Arr::wrap($middleware)
        );

        return $this;
    }

    /**
     * Get the middleware that should be removed from the route.
     */
    public function excludedMiddleware(): array
    {
        return (array) ($this->action['excluded_middleware'] ?? []);
    }

    /**
     * Indicate that the route should enforce scoping of multiple implicit Eloquent bindings.
     */
    public function scopeBindings(): static
    {
        $this->action['scope_bindings'] = true;

        return $this;
    }

    /**
     * Indicate that the route should not enforce scoping of multiple implicit Eloquent bindings.
     */
    public function withoutScopedBindings(): static
    {
        $this->action['scope_bindings'] = false;

        return $this;
    }

    /**
     * Determine if the route should enforce scoping of multiple implicit Eloquent bindings.
     */
    public function enforcesScopedBindings(): bool
    {
        return (bool) ($this->action['scope_bindings'] ?? false);
    }

    /**
     * Determine if the route should prevent scoping of multiple implicit Eloquent bindings.
     */
    public function preventsScopedBindings(): bool
    {
        return isset($this->action['scope_bindings']) && $this->action['scope_bindings'] === false;
    }

    /**
     * Specify that the route should not allow concurrent requests from the same session.
     */
    public function block(?int $lockSeconds = 10, ?int $waitSeconds = 10): static
    {
        $this->lockSeconds = $lockSeconds;
        $this->waitSeconds = $waitSeconds;

        return $this;
    }

    /**
     * Specify that the route should allow concurrent requests from the same session.
     */
    public function withoutBlocking(): static
    {
        return $this->block(null, null);
    }

    /**
     * Get the maximum number of seconds the route's session lock should be held for.
     */
    public function locksFor(): ?int
    {
        return $this->lockSeconds;
    }

    /**
     * Get the maximum number of seconds to wait while attempting to acquire a session lock.
     */
    public function waitsFor(): ?int
    {
        return $this->waitSeconds;
    }

    /**
     * Get the dispatcher for the route's controller.
     */
    public function controllerDispatcher(): ControllerDispatcherContract
    {
        if ($this->controllerDispatcher) {
            return $this->controllerDispatcher;
        }

        return $this->controllerDispatcher = $this->container->bound(ControllerDispatcherContract::class)
            ? $this->container->make(ControllerDispatcherContract::class)
            : new ControllerDispatcher($this->container);
    }

    /**
     * Get the route validators for the instance.
     *
     * @return array<int, \Hypervel\Routing\Matching\ValidatorInterface>
     */
    public static function getValidators(): array
    {
        if (isset(static::$validators)) {
            return static::$validators;
        }

        // To match the route, we will use a chain of responsibility pattern with the
        // validator implementations. We will spin through each one making sure it
        // passes and then we will know if the route as a whole matches request.
        return static::$validators = [
            new UriValidator(), new MethodValidator(),
            new SchemeValidator(), new HostValidator(),
        ];
    }

    /**
     * Convert the route to a Symfony route.
     */
    public function toSymfonyRoute(): SymfonyRoute
    {
        return new SymfonyRoute(
            preg_replace('/\{(\w+?)\?\}/', '{$1}', $this->uri()),
            $this->getOptionalParameterNames(),
            $this->wheres,
            ['utf8' => true],
            $this->getDomain() ?: '',
            [],
            $this->methods
        );
    }

    /**
     * Get the optional parameter names for the route.
     *
     * @return array<string, null>
     */
    public function getOptionalParameterNames(): array
    {
        preg_match_all('/\{(\w+?)\?\}/', $this->uri(), $matches);

        return array_fill_keys($matches[1], null);
    }

    /**
     * Get the compiled version of the route.
     */
    public function getCompiled(): ?CompiledRoute
    {
        return $this->compiled;
    }

    /**
     * Set the router instance on the route.
     */
    public function setRouter(Router $router): static
    {
        $this->router = $router;

        return $this;
    }

    /**
     * Set the container instance on the route.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;
        $this->controller = null;
        $this->shouldCacheControllerOnRoute = null;
        $this->callableDispatcher = null;
        $this->controllerDispatcher = null;

        return $this;
    }

    /**
     * Prepare the route instance for serialization.
     *
     * @throws LogicException
     */
    public function prepareForSerialization(): void
    {
        if ($this->action['uses'] instanceof Closure) {
            $this->action['uses'] = serialize(
                SerializableClosure::unsigned($this->action['uses'])
            );
        }

        if (isset($this->action['missing']) && $this->action['missing'] instanceof Closure) {
            $this->action['missing'] = serialize(
                SerializableClosure::unsigned($this->action['missing'])
            );
        }

        $this->compileRoute();

        $this->router = null;
        $this->container = null;
        $this->controller = null;
        $this->shouldCacheControllerOnRoute = null;
        $this->resolvedMiddleware = null;
        $this->callable = null;
        $this->missing = null;
        $this->callableDispatcher = null;
        $this->controllerDispatcher = null;
    }

    /**
     * Dynamically access route parameters.
     */
    public function __get(string $key): mixed
    {
        return $this->parameter($key);
    }
}
