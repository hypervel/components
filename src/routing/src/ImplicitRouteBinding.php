<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\InvalidValueException;
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
     * Cached signature parameters keyed by callable object.
     *
     * WeakMap ensures signature metadata disappears with the callable, preventing
     * stale binding metadata from leaking to later objects that reuse an object ID.
     *
     * @var null|WeakMap<object, array{0: array, 1: array}>
     */
    protected static ?WeakMap $objectSignatureCache = null;

    /**
     * Flush the static signature cache.
     *
     * Boot or tests only. Clears the process-wide signature caches shared by
     * every coroutine; next resolution re-reflects.
     */
    public static function flushCache(): void
    {
        static::$signatureCache = [];
        static::$objectSignatureCache = new WeakMap;
    }

    /**
     * Resolve the implicit route bindings for the given route.
     *
     * @throws ModelNotFoundException<Model>
     * @throws BackedEnumCaseNotFoundException
     */
    public static function resolveForRoute(Container $container, Route $route): void
    {
        $parameters = $route->parameters();

        if ($parameters === []) {
            return;
        }

        $action = $route->getAction('uses');

        if (is_string($action)) {
            [$urlRoutableParameters, $backedEnumParameters] = static::$signatureCache[$action]
                ??= [
                    $route->signatureParameters(['subClass' => UrlRoutable::class]),
                    $route->signatureParameters(['backedEnum' => true]),
                ];
        } else {
            $objectSignatureCache = static::$objectSignatureCache ??= new WeakMap;

            if (! isset($objectSignatureCache[$action])) {
                $objectSignatureCache[$action] = [
                    $route->signatureParameters(['subClass' => UrlRoutable::class]),
                    $route->signatureParameters(['backedEnum' => true]),
                ];
            }

            [$urlRoutableParameters, $backedEnumParameters] = $objectSignatureCache[$action];
        }

        static::resolveBackedEnumsForRoute($route, $parameters, $backedEnumParameters);

        foreach ($urlRoutableParameters as $parameter) {
            if (! $parameterName = static::getParameterName($parameter->getName(), $parameters)) {
                continue;
            }

            $parameterValue = $parameters[$parameterName];

            if ($parameterValue instanceof UrlRoutable) {
                continue;
            }

            $instance = $container->make(Reflector::getParameterClassName($parameter));

            $parent = $route->parentOfParameter($parameterName);

            // Soft-delete resolution exists only on models; other UrlRoutable implementations use the contract methods.
            $routeBindingMethod = $route->allowsTrashedBindings() && $instance instanceof Model && $instance::isSoftDeletable()
                ? 'resolveSoftDeletableRouteBinding'
                : 'resolveRouteBinding';

            try {
                if ($parent instanceof UrlRoutable
                    && ! $route->preventsScopedBindings()
                    && ($route->enforcesScopedBindings() || array_key_exists($parameterName, $route->bindingFields()))) {
                    $childRouteBindingMethod = $route->allowsTrashedBindings()
                        && $parent instanceof Model
                        && $instance instanceof Model
                        && $instance::isSoftDeletable()
                            ? 'resolveSoftDeletableChildRouteBinding'
                            : 'resolveChildRouteBinding';

                    if (! $model = $parent->{$childRouteBindingMethod}(
                        $parameterName,
                        $parameterValue,
                        $route->bindingFieldFor($parameterName)
                    )) {
                        throw (new ModelNotFoundException)->setModel(get_class($instance), $parameterValue === null ? [] : [$parameterValue]);
                    }
                } elseif (! $model = $instance->{$routeBindingMethod}($parameterValue, $route->bindingFieldFor($parameterName))) {
                    throw (new ModelNotFoundException)->setModel(get_class($instance), $parameterValue === null ? [] : [$parameterValue]);
                }
            } catch (InvalidValueException $e) {
                report_if(
                    $instance instanceof Model
                        ? $instance::reportsRouteModelBindingExceptions()
                        : Model::reportsRouteModelBindingExceptions(),
                    $e
                );

                throw (new ModelNotFoundException)->setModel(get_class($instance), $parameterValue === null ? [] : [$parameterValue]);
            }

            $route->setParameter($parameterName, $model);
        }
    }

    /**
     * Resolve the backed enum route bindings for the route.
     *
     * @throws BackedEnumCaseNotFoundException
     */
    protected static function resolveBackedEnumsForRoute(Route $route, array $parameters, array $backedEnumParameters): void
    {
        foreach ($backedEnumParameters as $parameter) {
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
