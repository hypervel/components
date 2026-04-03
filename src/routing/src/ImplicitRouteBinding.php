<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Hypervel\Support\Reflector;
use Hypervel\Support\Str;
use WeakMap;

class ImplicitRouteBinding
{
    /**
     * Cached signature parameters keyed by action.
     * Persists for worker lifetime — reflection resolved once per action.
     *
     * @var array<string, array{0: array, 1: array}>
     */
    protected static array $signatureCache = [];

    /**
     * Cached signature parameters keyed by closure object.
     *
     * WeakMap ensures signature metadata disappears with the closure, preventing
     * stale binding metadata from leaking to later closures that reuse an object ID.
     *
     * @var null|WeakMap<Closure, array{0: array, 1: array}>
     */
    protected static ?WeakMap $closureSignatureCache = null;

    /**
     * Flush the static signature cache.
     */
    public static function flushCache(): void
    {
        static::$signatureCache = [];
        static::$closureSignatureCache = new WeakMap();
    }

    /**
     * Resolve the implicit route bindings for the given route.
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<\Hypervel\Database\Eloquent\Model>
     * @throws \Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException
     */
    public static function resolveForRoute(Container $container, Route $route): void
    {
        $parameters = $route->parameters();

        $action = $route->getAction('uses');

        if (is_string($action)) {
            [$urlRoutableParams, $backedEnumParams] = static::$signatureCache[$action]
                ??= [
                    $route->signatureParameters(['subClass' => UrlRoutable::class]),
                    $route->signatureParameters(['backedEnum' => true]),
                ];
        } else {
            $closureSignatureCache = static::$closureSignatureCache ??= new WeakMap();

            if (! isset($closureSignatureCache[$action])) {
                $closureSignatureCache[$action] = [
                    $route->signatureParameters(['subClass' => UrlRoutable::class]),
                    $route->signatureParameters(['backedEnum' => true]),
                ];
            }

            [$urlRoutableParams, $backedEnumParams] = $closureSignatureCache[$action];
        }

        static::resolveBackedEnumsForRoute($route, $parameters, $backedEnumParams);

        foreach ($urlRoutableParams as $parameter) {
            if (! $parameterName = static::getParameterName($parameter->getName(), $parameters)) {
                continue;
            }

            $parameterValue = $parameters[$parameterName];

            if ($parameterValue instanceof UrlRoutable) {
                continue;
            }

            $instance = $container->make(Reflector::getParameterClassName($parameter));

            $parent = $route->parentOfParameter($parameterName);

            $routeBindingMethod = $route->allowsTrashedBindings() && $instance::isSoftDeletable()
                ? 'resolveSoftDeletableRouteBinding'
                : 'resolveRouteBinding';

            if ($parent instanceof UrlRoutable
                && ! $route->preventsScopedBindings()
                && ($route->enforcesScopedBindings() || array_key_exists($parameterName, $route->bindingFields()))) {
                $childRouteBindingMethod = $route->allowsTrashedBindings() && $instance::isSoftDeletable()
                    ? 'resolveSoftDeletableChildRouteBinding'
                    : 'resolveChildRouteBinding';

                if (! $model = $parent->{$childRouteBindingMethod}( /* @phpstan-ignore method.notFound (resolveSoftDeletableChildRouteBinding exists on Model via SoftDeletes trait, not on UrlRoutable contract) */
                    $parameterName,
                    $parameterValue,
                    $route->bindingFieldFor($parameterName)
                )) {
                    throw (new ModelNotFoundException())->setModel(get_class($instance), [$parameterValue]);
                }
            } elseif (! $model = $instance->{$routeBindingMethod}($parameterValue, $route->bindingFieldFor($parameterName))) {
                throw (new ModelNotFoundException())->setModel(get_class($instance), [$parameterValue]);
            }

            $route->setParameter($parameterName, $model);
        }
    }

    /**
     * Resolve the backed enum route bindings for the route.
     *
     * @throws \Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException
     */
    protected static function resolveBackedEnumsForRoute(Route $route, array $parameters, array $backedEnumParams): void
    {
        foreach ($backedEnumParams as $parameter) {
            if (! $parameterName = static::getParameterName($parameter->getName(), $parameters)) {
                continue;
            }

            $parameterValue = $parameters[$parameterName];

            if ($parameterValue === null) {
                continue;
            }

            $backedEnumClass = $parameter->getType()?->getName();

            $backedEnum = $parameterValue instanceof $backedEnumClass
                ? $parameterValue
                : $backedEnumClass::tryFrom((string) $parameterValue);

            if (is_null($backedEnum)) {
                throw new BackedEnumCaseNotFoundException($backedEnumClass, $parameterValue);
            }

            $route->setParameter($parameterName, $backedEnum);
        }
    }

    /**
     * Return the parameter name if it exists in the given parameters.
     */
    protected static function getParameterName(string $name, array $parameters): ?string
    {
        if (array_key_exists($name, $parameters)) {
            return $name;
        }

        if (array_key_exists($snakedName = Str::snake($name), $parameters)) {
            return $snakedName;
        }

        return null;
    }
}
