<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Contracts\Container\Container;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotCreateData;
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
     * Instantiate a metadata-proven exact-array node directly.
     *
     * Declaration validation owns constructor/property correspondence, exact-array
     * creation omits computed keys, and class metadata proves public, complete,
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
     * Instantiate and assign one fully cast data node.
     *
     * @param array<string, mixed> $properties
     */
    public function instantiate(DataClass $dataClass, array $properties): BaseData
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

            if (! array_key_exists($property->name, $properties)) {
                if (! $property->hasDefaultValue) {
                    throw CannotCreateData::propertyMissing($dataClass, $property);
                }

                continue;
            }

            $data->{$property->name} = $properties[$property->name];
        }

        return $data;
    }
}
