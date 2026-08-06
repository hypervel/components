<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use BackedEnum;
use BadMethodCallException;
use Closure;
use Hypervel\Support\Arr;
use Hypervel\Support\Reflector;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;

/**
 * @method \Hypervel\Routing\Route any(string $uri, \Closure|array|string|null $action = null)
 * @method \Hypervel\Routing\Route delete(string $uri, \Closure|array|string|null $action = null)
 * @method \Hypervel\Routing\Route get(string $uri, \Closure|array|string|null $action = null)
 * @method \Hypervel\Routing\Route options(string $uri, \Closure|array|string|null $action = null)
 * @method \Hypervel\Routing\Route patch(string $uri, \Closure|array|string|null $action = null)
 * @method \Hypervel\Routing\Route post(string $uri, \Closure|array|string|null $action = null)
 * @method \Hypervel\Routing\Route put(string $uri, \Closure|array|string|null $action = null)
 * @method $this as(string $value)
 * @method $this can(\UnitEnum|string $ability, array|string $models = [])
 * @method $this controller(string $controller)
 * @method $this domain(\BackedEnum|string $value)
 * @method $this metadata(array $metadata)
 * @method $this middleware(null|array|string $middleware)
 * @method $this missing(\Closure $missing)
 * @method $this name(\BackedEnum|string $value)
 * @method $this namespace(null|string $value)
 * @method $this port(int $port)
 * @method $this prefix(string $prefix)
 * @method $this scopeBindings()
 * @method $this where(array $where)
 * @method $this withoutMiddleware(array|string $middleware)
 * @method $this withoutScopedBindings()
 */
class RouteRegistrar
{
    use CreatesRegularExpressionRouteConstraints;
    use Macroable {
        __call as macroCall;
    }

    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The attributes to pass on to the router.
     */
    protected array $attributes = [];

    /**
     * The methods to dynamically pass through to the router.
     *
     * @var string[]
     */
    protected array $passthru = [
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any',
    ];

    /**
     * The attributes that can be set through this class.
     *
     * @var string[]
     */
    protected array $allowedAttributes = [
        'as',
        'can',
        'controller',
        'domain',
        'metadata',
        'middleware',
        'missing',
        'name',
        'namespace',
        'port',
        'prefix',
        'scopeBindings',
        'where',
        'withoutMiddleware',
        'withoutScopedBindings',
    ];

    /**
     * The attributes that are aliased.
     */
    protected array $aliases = [
        'name' => 'as',
        'scopeBindings' => 'scope_bindings',
        'withoutScopedBindings' => 'scope_bindings',
        'withoutMiddleware' => 'excluded_middleware',
    ];

    /**
     * Create a new route registrar instance.
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Set the value for a given attribute.
     *
     * @throws InvalidArgumentException
     */
    public function attribute(string $key, mixed $value): static
    {
        if (! in_array($key, $this->allowedAttributes)) {
            throw new InvalidArgumentException("Attribute [{$key}] does not exist.");
        }

        if ($key === 'middleware') {
            $value = array_filter(Arr::wrap($value));

            foreach ($value as $index => $middleware) {
                $value[$index] = (string) $middleware;
            }
        }

        if ($key === 'metadata') {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Attribute [metadata] expects an array.');
            }

            $value = RouteGroup::mergeMetadata(
                $this->attributes['metadata'] ?? [],
                $value
            );
        }

        $attributeKey = Arr::get($this->aliases, $key, $key);

        if ($key === 'withoutMiddleware') {
            $value = array_merge(
                (array) ($this->attributes[$attributeKey] ?? []),
                Arr::wrap($value)
            );
        }

        if ($key === 'withoutScopedBindings') {
            $value = false;
        }

        if ($value instanceof BackedEnum && ! is_string($value = $value->value)) {
            throw new InvalidArgumentException("Attribute [{$key}] expects a string backed enum.");
        }

        $this->attributes[$attributeKey] = $value;

        return $this;
    }

    /**
     * Route a resource to a controller.
     */
    public function resource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        return $this->router->resource($name, $controller, $this->attributes + $options);
    }

    /**
     * Route an API resource to a controller.
     */
    public function apiResource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        return $this->router->apiResource($name, $controller, $this->attributes + $options);
    }

    /**
     * Route a singleton resource to a controller.
     */
    public function singleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration
    {
        return $this->router->singleton($name, $controller, $this->attributes + $options);
    }

    /**
     * Route an API singleton resource to a controller.
     */
    public function apiSingleton(string $name, string $controller, array $options = []): PendingSingletonResourceRegistration
    {
        return $this->router->apiSingleton($name, $controller, $this->attributes + $options);
    }

    /**
     * Create a route group with shared attributes.
     */
    public function group(Closure|array|string $callback): static
    {
        $this->router->group($this->attributes, $callback);

        return $this;
    }

    /**
     * Register a new route with the given verbs.
     */
    public function match(array|string $methods, string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->router->match($methods, $uri, $this->compileAction($action));
    }

    /**
     * Add metadata to routes registered by the registrar.
     */
    public function metadata(array $metadata): static
    {
        return $this->attribute('metadata', $metadata);
    }

    /**
     * Register a new route with the router.
     */
    protected function registerRoute(string $method, string $uri, Closure|array|string|null $action = null): Route
    {
        if (! is_array($action)) {
            $action = array_merge($this->attributes, $action ? ['uses' => $action] : []);
        }

        return $this->router->{$method}($uri, $this->compileAction($action));
    }

    /**
     * Compile the action into an array including the attributes.
     */
    protected function compileAction(Closure|array|string|null $action): array
    {
        if (is_null($action)) {
            return $this->attributes;
        }

        if (is_string($action) || $action instanceof Closure) {
            $action = ['uses' => $action];
        }

        if (array_is_list($action)
            && Reflector::isCallable($action)) {
            if (strncmp($action[0], '\\', 1)) {
                $action[0] = '\\' . $action[0];
            }
            $action = [
                'uses' => $action[0] . '@' . $action[1],
                'controller' => $action[0] . '@' . $action[1],
            ];
        }

        $metadata = RouteGroup::mergeMetadata(
            $this->attributes['metadata'] ?? [],
            $action['metadata'] ?? [],
        );

        $action = array_merge($this->attributes, $action);

        if ($metadata !== []) {
            $action['metadata'] = $metadata;
        }

        return $action;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }

    /**
     * Dynamically handle calls into the route registrar.
     *
     * @return \Hypervel\Routing\Route|static
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (in_array($method, $this->passthru)) {
            return $this->registerRoute($method, ...$parameters);
        }

        if (in_array($method, $this->allowedAttributes)) {
            if ($method === 'middleware') {
                return $this->attribute($method, is_array($parameters[0]) ? $parameters[0] : $parameters);
            }

            if ($method === 'can') {
                return $this->attribute($method, [$parameters]);
            }

            return $this->attribute($method, array_key_exists(0, $parameters) ? $parameters[0] : true);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $method
        ));
    }
}
