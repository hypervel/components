<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Support\Str;

class RouteBinding
{
    /**
     * Create a Route model binding for a given callback.
     */
    public static function forCallback(Container $container, Closure|string $binder): Closure
    {
        if (is_string($binder)) {
            return static::createClassBinding($container, $binder);
        }

        return $binder;
    }

    /**
     * Create a class based binding using the IoC container.
     */
    protected static function createClassBinding(Container $container, string $binding): Closure
    {
        return function ($value, $route) use ($container, $binding) {
            // If the binding has an @ sign, we will assume it's being used to delimit
            // the class name from the bind method name. This allows for bindings
            // to run multiple bind methods in a single class for convenience.
            [$class, $method] = Str::parseCallback($binding, 'bind');

            $callable = [$container->make($class), $method];

            return $callable($value, $route);
        };
    }

    /**
     * Create a Route model binding for a model.
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<\Hypervel\Database\Eloquent\Model>
     */
    public static function forModel(Container $container, string $class, ?Closure $callback = null): Closure
    {
        return function ($value, $route = null) use ($container, $class, $callback) {
            if (is_null($value)) {
                return null;
            }

            // For model binders, we will attempt to retrieve the models using the first
            // method on the model instance. If we cannot retrieve the models we'll
            // throw a not found exception otherwise we will return the instance.
            $instance = $container->make($class);

            $routeBindingMethod = $route?->allowsTrashedBindings() && $instance::isSoftDeletable()
                ? 'resolveSoftDeletableRouteBinding'
                : 'resolveRouteBinding';

            if ($model = $instance->{$routeBindingMethod}($value)) {
                return $model;
            }

            // If a callback was supplied to the method we will call that to determine
            // what we should do when the model is not found. This just gives these
            // developer a little greater flexibility to decide what will happen.
            if ($callback instanceof Closure) {
                return $callback($value);
            }

            throw (new ModelNotFoundException())->setModel($class);
        };
    }
}
