<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Support\Str;

class ResourceRegistrar
{
    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The default actions for a resourceful controller.
     *
     * @var string[]
     */
    protected array $resourceDefaults = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    /**
     * The default actions for a singleton resource controller.
     *
     * @var string[]
     */
    protected array $singletonResourceDefaults = ['show', 'edit', 'update'];

    /**
     * The parameters set for this resource instance.
     */
    protected array|string|null $parameters = null;

    /**
     * The global parameter mapping.
     */
    protected static array $parameterMap = [];

    /**
     * Singular global parameters.
     */
    protected static bool $singularParameters = true;

    /**
     * The verbs used in the resource URIs.
     */
    protected static array $verbs = [
        'create' => 'create',
        'edit' => 'edit',
    ];

    /**
     * Create a new resource registrar instance.
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Route a resource to a controller.
     */
    public function register(string $name, string $controller, array $options = []): ?RouteCollection
    {
        if (isset($options['parameters']) && ! isset($this->parameters)) {
            $this->parameters = $options['parameters'];
        }

        // If the resource name contains a slash, we will assume the developer wishes to
        // register these resource routes with a prefix so we will set that up out of
        // the box so they don't have to mess with it. Otherwise, we will continue.
        if (str_contains($name, '/')) {
            $this->prefixedResource($name, $controller, $options);

            return null;
        }

        // We need to extract the base resource from the resource name. Nested resources
        // are supported in the framework, but we need to know what name to use for a
        // place-holder on the route parameters, which should be the base resources.
        $base = $this->getResourceWildcard(last(explode('.', $name)));

        $defaults = $this->resourceDefaults;

        $collection = new RouteCollection();

        $resourceMethods = $this->getResourceMethods($defaults, $options);

        foreach ($resourceMethods as $m) {
            $optionsForMethod = $options;

            if (isset($optionsForMethod['middleware_for'][$m])) {
                $optionsForMethod['middleware'] = $optionsForMethod['middleware_for'][$m];
            }

            if (isset($optionsForMethod['excluded_middleware_for'][$m])) {
                $optionsForMethod['excluded_middleware'] = Router::uniqueMiddleware(array_merge(
                    $optionsForMethod['excluded_middleware'] ?? [],
                    $optionsForMethod['excluded_middleware_for'][$m]
                ));
            }

            $route = $this->{'addResource' . ucfirst($m)}(
                $name,
                $base,
                $controller,
                $optionsForMethod
            );

            if (isset($options['bindingFields'])) {
                $this->setResourceBindingFields($route, $options['bindingFields']);
            }

            if (isset($options['trashed'])
                && in_array($m, ! empty($options['trashed']) ? $options['trashed'] : array_intersect($resourceMethods, ['show', 'edit', 'update']))) {
                $route->withTrashed();
            }

            $collection->add($route);
        }

        return $collection;
    }

    /**
     * Route a singleton resource to a controller.
     */
    public function singleton(string $name, string $controller, array $options = []): ?RouteCollection
    {
        if (isset($options['parameters']) && ! isset($this->parameters)) {
            $this->parameters = $options['parameters'];
        }

        // If the resource name contains a slash, we will assume the developer wishes to
        // register these singleton routes with a prefix so we will set that up out of
        // the box so they don't have to mess with it. Otherwise, we will continue.
        if (str_contains($name, '/')) {
            $this->prefixedSingleton($name, $controller, $options);

            return null;
        }

        $defaults = $this->singletonResourceDefaults;

        if (isset($options['creatable'])) {
            $defaults = array_merge($defaults, ['create', 'store', 'destroy']);
        } elseif (isset($options['destroyable'])) {
            $defaults = array_merge($defaults, ['destroy']);
        }

        $collection = new RouteCollection();

        $resourceMethods = $this->getResourceMethods($defaults, $options);

        foreach ($resourceMethods as $m) {
            $optionsForMethod = $options;

            if (isset($optionsForMethod['middleware_for'][$m])) {
                $optionsForMethod['middleware'] = $optionsForMethod['middleware_for'][$m];
            }

            if (isset($optionsForMethod['excluded_middleware_for'][$m])) {
                $optionsForMethod['excluded_middleware'] = Router::uniqueMiddleware(array_merge(
                    $optionsForMethod['excluded_middleware'] ?? [],
                    $optionsForMethod['excluded_middleware_for'][$m]
                ));
            }

            $route = $this->{'addSingleton' . ucfirst($m)}(
                $name,
                $controller,
                $optionsForMethod
            );

            if (isset($options['bindingFields'])) {
                $this->setResourceBindingFields($route, $options['bindingFields']);
            }

            $collection->add($route);
        }

        return $collection;
    }

    /**
     * Build a set of prefixed resource routes.
     */
    protected function prefixedResource(string $name, string $controller, array $options): Router
    {
        [$name, $prefix] = $this->getResourcePrefix($name);

        // We need to extract the base resource from the resource name. Nested resources
        // are supported in the framework, but we need to know what name to use for a
        // place-holder on the route parameters, which should be the base resources.
        $callback = function ($me) use ($name, $controller, $options) {
            $me->resource($name, $controller, $options);
        };

        return $this->router->group(compact('prefix'), $callback);
    }

    /**
     * Build a set of prefixed singleton routes.
     */
    protected function prefixedSingleton(string $name, string $controller, array $options): Router
    {
        [$name, $prefix] = $this->getResourcePrefix($name);

        // We need to extract the base resource from the resource name. Nested resources
        // are supported in the framework, but we need to know what name to use for a
        // place-holder on the route parameters, which should be the base resources.
        $callback = function ($me) use ($name, $controller, $options) {
            $me->singleton($name, $controller, $options);
        };

        return $this->router->group(compact('prefix'), $callback);
    }

    /**
     * Extract the resource and prefix from a resource name.
     */
    protected function getResourcePrefix(string $name): array
    {
        $segments = explode('/', $name);

        // To get the prefix, we will take all of the name segments and implode them on
        // a slash. This will generate a proper URI prefix for us. Then we take this
        // last segment, which will be considered the final resources name we use.
        $prefix = implode('/', array_slice($segments, 0, -1));

        return [end($segments), $prefix];
    }

    /**
     * Get the applicable resource methods.
     */
    protected function getResourceMethods(array $defaults, array $options): array
    {
        $methods = $defaults;

        if (isset($options['only'])) {
            $methods = array_intersect($methods, (array) $options['only']);
        }

        if (isset($options['except'])) {
            $methods = array_diff($methods, (array) $options['except']);
        }

        return array_values($methods);
    }

    /**
     * Add the index method for a resourceful route.
     */
    protected function addResourceIndex(string $name, string $base, string $controller, array $options): Route
    {
        $uri = $this->getResourceUri($name);

        unset($options['missing']);

        $action = $this->getResourceAction($name, $controller, 'index', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the create method for a resourceful route.
     */
    protected function addResourceCreate(string $name, string $base, string $controller, array $options): Route
    {
        $uri = $this->getResourceUri($name) . '/' . static::$verbs['create'];

        unset($options['missing']);

        $action = $this->getResourceAction($name, $controller, 'create', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the store method for a resourceful route.
     */
    protected function addResourceStore(string $name, string $base, string $controller, array $options): Route
    {
        $uri = $this->getResourceUri($name);

        unset($options['missing']);

        $action = $this->getResourceAction($name, $controller, 'store', $options);

        return $this->router->post($uri, $action);
    }

    /**
     * Add the show method for a resourceful route.
     */
    protected function addResourceShow(string $name, string $base, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $base . '}';

        $action = $this->getResourceAction($name, $controller, 'show', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the edit method for a resourceful route.
     */
    protected function addResourceEdit(string $name, string $base, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $base . '}/' . static::$verbs['edit'];

        $action = $this->getResourceAction($name, $controller, 'edit', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the update method for a resourceful route.
     */
    protected function addResourceUpdate(string $name, string $base, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $base . '}';

        $action = $this->getResourceAction($name, $controller, 'update', $options);

        return $this->router->match(['PUT', 'PATCH'], $uri, $action);
    }

    /**
     * Add the destroy method for a resourceful route.
     */
    protected function addResourceDestroy(string $name, string $base, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/{' . $base . '}';

        $action = $this->getResourceAction($name, $controller, 'destroy', $options);

        return $this->router->delete($uri, $action);
    }

    /**
     * Add the create method for a singleton route.
     */
    protected function addSingletonCreate(string $name, string $controller, array $options): Route
    {
        $uri = $this->getResourceUri($name) . '/' . static::$verbs['create'];

        unset($options['missing']);

        $action = $this->getResourceAction($name, $controller, 'create', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the store method for a singleton route.
     */
    protected function addSingletonStore(string $name, string $controller, array $options): Route
    {
        $uri = $this->getResourceUri($name);

        unset($options['missing']);

        $action = $this->getResourceAction($name, $controller, 'store', $options);

        return $this->router->post($uri, $action);
    }

    /**
     * Add the show method for a singleton route.
     */
    protected function addSingletonShow(string $name, string $controller, array $options): Route
    {
        $uri = $this->getResourceUri($name);

        unset($options['missing']);

        $action = $this->getResourceAction($name, $controller, 'show', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the edit method for a singleton route.
     */
    protected function addSingletonEdit(string $name, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name) . '/' . static::$verbs['edit'];

        $action = $this->getResourceAction($name, $controller, 'edit', $options);

        return $this->router->get($uri, $action);
    }

    /**
     * Add the update method for a singleton route.
     */
    protected function addSingletonUpdate(string $name, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name);

        $action = $this->getResourceAction($name, $controller, 'update', $options);

        return $this->router->match(['PUT', 'PATCH'], $uri, $action);
    }

    /**
     * Add the destroy method for a singleton route.
     */
    protected function addSingletonDestroy(string $name, string $controller, array $options): Route
    {
        $name = $this->getShallowName($name, $options);

        $uri = $this->getResourceUri($name);

        $action = $this->getResourceAction($name, $controller, 'destroy', $options);

        return $this->router->delete($uri, $action);
    }

    /**
     * Get the name for a given resource with shallowness applied when applicable.
     */
    protected function getShallowName(string $name, array $options): string
    {
        return isset($options['shallow']) && $options['shallow']
            ? last(explode('.', $name))
            : $name;
    }

    /**
     * Set the route's binding fields if the resource is scoped.
     */
    protected function setResourceBindingFields(Route $route, array $bindingFields): void
    {
        preg_match_all('/(?<={).*?(?=})/', $route->uri, $matches);

        $fields = array_fill_keys($matches[0], null);

        $route->setBindingFields(array_replace(
            $fields,
            array_intersect_key($bindingFields, $fields)
        ));
    }

    /**
     * Get the base resource URI for a given resource.
     */
    public function getResourceUri(string $resource): string
    {
        if (! str_contains($resource, '.')) {
            return $resource;
        }

        // Once we have built the base URI, we'll remove the parameter holder for this
        // base resource name so that the individual route adders can suffix these
        // paths however they need to, as some do not have any parameters at all.
        $segments = explode('.', $resource);

        $uri = $this->getNestedResourceUri($segments);

        return str_replace('/{' . $this->getResourceWildcard(end($segments)) . '}', '', $uri);
    }

    /**
     * Get the URI for a nested resource segment array.
     */
    protected function getNestedResourceUri(array $segments): string
    {
        // We will spin through the segments and create a place-holder for each of the
        // resource segments, as well as the resource itself. Then we should get an
        // entire string for the resource URI that contains all nested resources.
        return implode('/', array_map(function ($s) {
            return $s . '/{' . $this->getResourceWildcard($s) . '}';
        }, $segments));
    }

    /**
     * Format a resource parameter for usage.
     */
    public function getResourceWildcard(string $value): string
    {
        if (isset($this->parameters[$value])) {
            $value = $this->parameters[$value];
        } elseif (isset(static::$parameterMap[$value])) {
            $value = static::$parameterMap[$value];
        } elseif ($this->parameters === 'singular' || static::$singularParameters) {
            $value = Str::singular($value);
        }

        return str_replace('-', '_', $value);
    }

    /**
     * Get the action array for a resource route.
     */
    protected function getResourceAction(string $resource, string $controller, string $method, array $options): array
    {
        $name = $this->getResourceRouteName($resource, $method, $options);

        $action = ['as' => $name, 'uses' => $controller . '@' . $method];

        if (isset($options['middleware'])) {
            $action['middleware'] = $options['middleware'];
        }

        if (isset($options['excluded_middleware'])) {
            $action['excluded_middleware'] = $options['excluded_middleware'];
        }

        if (isset($options['wheres'])) {
            $action['where'] = $options['wheres'];
        }

        if (isset($options['missing'])) {
            $action['missing'] = $options['missing'];
        }

        return $action;
    }

    /**
     * Get the name for a given resource.
     */
    protected function getResourceRouteName(string $resource, string $method, array $options): string
    {
        $name = $resource;

        // If the names array has been provided to us we will check for an entry in the
        // array first. We will also check for the specific method within this array
        // so the names may be specified on a more "granular" level using methods.
        if (isset($options['names'])) {
            if (is_string($options['names'])) {
                $name = $options['names'];
            } elseif (isset($options['names'][$method])) {
                return $options['names'][$method];
            }
        }

        // If a global prefix has been assigned to all names for this resource, we will
        // grab that so we can prepend it onto the name when we create this name for
        // the resource action. Otherwise we'll just use an empty string for here.
        $prefix = isset($options['as']) ? $options['as'] . '.' : '';

        return trim(sprintf('%s%s.%s', $prefix, $name, $method), '.');
    }

    /**
     * Set or unset the unmapped global parameters to singular.
     */
    public static function singularParameters(bool $singular = true): void
    {
        static::$singularParameters = $singular;
    }

    /**
     * Get the global parameter map.
     */
    public static function getParameters(): array
    {
        return static::$parameterMap;
    }

    /**
     * Set the global parameter mapping.
     */
    public static function setParameters(array $parameters = []): void
    {
        static::$parameterMap = $parameters;
    }

    /**
     * Get or set the action verbs used in the resource URIs.
     */
    public static function verbs(array $verbs = []): array
    {
        if (empty($verbs)) {
            return static::$verbs;
        }

        static::$verbs = array_merge(static::$verbs, $verbs);

        return static::$verbs;
    }
}
