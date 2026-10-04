<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Container\Container;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\DataClass;

class DataInstantiator
{
    /**
     * Create a data instantiator.
     */
    public function __construct(
        protected readonly Container $container,
    ) {
    }

    /**
     * Instantiate a metadata-proven lean array node directly.
     *
     * Declaration validation owns constructor/property correspondence, the creation
     * recipe omits computed keys, and class metadata proves public, complete,
     * non-variadic constructor ownership without contextual parameters.
     *
     * @internal
     *
     * @param array<string, mixed> $properties
     */
    public function instantiateDirect(DataClass $dataClass, array $properties): BaseData
    {
        $class = $dataClass->name;

        return new $class(...$properties);
    }

    /**
     * Resolve the contextual constructor values of a data class in its container build context.
     *
     * @param null|list<string> $names the parameters to resolve, or null for every contextual parameter
     * @return array<string, mixed>
     */
    public function resolveContextualParameters(DataClass $dataClass, ?array $names = null): array
    {
        return $this->container->resolveContextualParameters($dataClass->name, $names);
    }

    /**
     * Instantiate and assign one fully cast data node.
     *
     * @param array<string, mixed> $properties
     * @param bool $useOptionalValues whether a missing nullable Optional property outside the constructor receives Optional rather than null
     */
    public function instantiate(DataClass $dataClass, array $properties, bool $useOptionalValues = true): BaseData
    {
        if ($dataClass->constructor !== null && ! $dataClass->constructor->isPublic()) {
            throw CannotCreateData::nonPublicConstructor($dataClass);
        }

        if ($dataClass->constructor?->isVariadic()) {
            throw CannotCreateData::variadicConstructor($dataClass);
        }

        $parameters = [];
        $requiresContainer = false;

        foreach ($dataClass->constructorParameters as $parameter) {
            if ($parameter->contextualAttribute !== null) {
                // Resolved values are passed as overrides; the container resolves any that were not.
                if (array_key_exists($parameter->name, $properties)) {
                    $parameters[$parameter->name] = $properties[$parameter->name];
                }

                $requiresContainer = true;

                continue;
            }

            if (array_key_exists($parameter->name, $properties)) {
                $parameters[$parameter->name] = $properties[$parameter->name];

                continue;
            }

            if (! $parameter->hasDefaultValue) {
                throw CannotCreateData::constructorMissingParameters($dataClass, $parameters);
            }
        }

        $class = $dataClass->name;

        /** @var BaseData $data */
        $data = $requiresContainer
            ? $this->container->buildWith($class, $parameters)
            : new $class(...$parameters);

        foreach ($dataClass->properties as $property) {
            if ($property->isConstructorParameter || $property->computed) {
                continue;
            }

            $value = $properties[$property->name] ?? null;

            // A property promoted by an ancestor constructor belongs to the constructor chain, so input never replaces it.
            if (! $property->isPromoted
                && array_key_exists($property->name, $properties)
                && ! $value instanceof Optional
            ) {
                $data->{$property->name} = $value;

                continue;
            }

            // Absent input, including Optional, keeps a value the constructor assigned.
            if ($property->reflection->isInitialized($data)) {
                continue;
            }

            if ($property->type->isOptional && ($useOptionalValues || ! $property->type->isNullable)) {
                $data->{$property->name} = Optional::create();
            } elseif ($property->type->isNullable) {
                $data->{$property->name} = null;
            } else {
                throw CannotCreateData::propertyMissing($dataClass, $property);
            }
        }

        return $data;
    }
}
