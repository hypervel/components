<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;

class PendingResourceRegistration
{
    use CreatesRegularExpressionRouteConstraints;
    use Macroable;

    /**
     * The resource registrar.
     */
    protected ResourceRegistrar $registrar;

    /**
     * The resource name.
     */
    protected string $name;

    /**
     * The resource controller.
     */
    protected string $controller;

    /**
     * The resource options.
     */
    protected array $options = [];

    /**
     * The resource's registration status.
     */
    protected bool $registered = false;

    /**
     * Create a new pending resource registration instance.
     */
    public function __construct(ResourceRegistrar $registrar, string $name, string $controller, array $options)
    {
        $this->name = $name;
        $this->options = $options;
        $this->registrar = $registrar;
        $this->controller = $controller;
    }

    /**
     * Set the methods the controller should apply to.
     */
    public function only(array|string $methods): static
    {
        $this->options['only'] = is_array($methods) ? $methods : func_get_args();

        return $this;
    }

    /**
     * Set the methods the controller should exclude.
     */
    public function except(array|string $methods): static
    {
        $this->options['except'] = is_array($methods) ? $methods : func_get_args();

        return $this;
    }

    /**
     * Set the route names for controller actions.
     */
    public function names(array|string $names): static
    {
        $this->options['names'] = $names;

        return $this;
    }

    /**
     * Set the route name for a controller action.
     */
    public function name(string $method, string $name): static
    {
        $this->options['names'][$method] = $name;

        return $this;
    }

    /**
     * Override the route parameter names.
     */
    public function parameters(array|string $parameters): static
    {
        $this->options['parameters'] = $parameters;

        return $this;
    }

    /**
     * Override a route parameter's name.
     */
    public function parameter(string $previous, string $new): static
    {
        $this->options['parameters'][$previous] = $new;

        return $this;
    }

    /**
     * Add middleware to the resource routes.
     */
    public function middleware(mixed $middleware): static
    {
        $middleware = Arr::wrap($middleware);

        foreach ($middleware as $key => $value) {
            $middleware[$key] = (string) $value;
        }

        $this->options['middleware'] = $middleware;

        if (isset($this->options['middleware_for'])) {
            foreach ($this->options['middleware_for'] as $method => $value) {
                $this->options['middleware_for'][$method] = Router::uniqueMiddleware(array_merge(
                    Arr::wrap($value),
                    $middleware
                ));
            }
        }

        return $this;
    }

    /**
     * Specify middleware that should be added to the specified resource routes.
     */
    public function middlewareFor(array|string $methods, array|string $middleware): static
    {
        $methods = Arr::wrap($methods);
        $middleware = Arr::wrap($middleware);

        if (isset($this->options['middleware'])) {
            $middleware = Router::uniqueMiddleware(array_merge(
                $this->options['middleware'],
                $middleware
            ));
        }

        foreach ($methods as $method) {
            $this->options['middleware_for'][$method] = $middleware;
        }

        return $this;
    }

    /**
     * Specify middleware that should be removed from the resource routes.
     */
    public function withoutMiddleware(array|string $middleware): static
    {
        $this->options['excluded_middleware'] = array_merge(
            (array) ($this->options['excluded_middleware'] ?? []),
            Arr::wrap($middleware)
        );

        return $this;
    }

    /**
     * Specify middleware that should be removed from the specified resource routes.
     */
    public function withoutMiddlewareFor(array|string $methods, array|string $middleware): static
    {
        $methods = Arr::wrap($methods);
        $middleware = Arr::wrap($middleware);

        foreach ($methods as $method) {
            $this->options['excluded_middleware_for'][$method] = $middleware;
        }

        return $this;
    }

    /**
     * Add "where" constraints to the resource routes.
     */
    public function where(mixed $wheres): static
    {
        $this->options['wheres'] = $wheres;

        return $this;
    }

    /**
     * Indicate that the resource routes should have "shallow" nesting.
     */
    public function shallow(bool $shallow = true): static
    {
        $this->options['shallow'] = $shallow;

        return $this;
    }

    /**
     * Define the callable that should be invoked on a missing model exception.
     */
    public function missing(callable $callback): static
    {
        $this->options['missing'] = $callback;

        return $this;
    }

    /**
     * Indicate that the resource routes should be scoped using the given binding fields.
     */
    public function scoped(array $fields = []): static
    {
        $this->options['bindingFields'] = $fields;

        return $this;
    }

    /**
     * Define which routes should allow "trashed" models to be retrieved when resolving implicit model bindings.
     */
    public function withTrashed(array $methods = []): static
    {
        $this->options['trashed'] = $methods;

        return $this;
    }

    /**
     * Register the resource route.
     */
    public function register(): ?RouteCollection
    {
        $this->registered = true;

        return $this->registrar->register(
            $this->name,
            $this->controller,
            $this->options
        );
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
        if (! $this->registered) {
            $this->register();
        }
    }
}
