<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use ReflectionMethod;

/**
 * Immutable construction and transformation metadata for one data class.
 */
final readonly class DataClass
{
    /**
     * Create a new data class definition.
     *
     * @param class-string<BaseData> $name
     * @param array<string, DataProperty> $properties
     * @param array<string, DataMethod> $methods
     * @param list<DataParameter> $constructorParameters
     * @param array<string, true> $lifecycleMethods
     * @param array<string, non-empty-list<DataIterableAnnotation>> $dataIterablePropertyAnnotations
     * @param array<array-key, string> $outputMappedProperties
     */
    public function __construct(
        public readonly string $name,
        public readonly array $properties,
        public readonly array $methods,
        public readonly ?ReflectionMethod $constructor,
        public readonly array $constructorParameters,
        public readonly bool $isReadonly,
        public readonly bool $isAbstract,
        public readonly bool $isFinal,
        public readonly bool $propertyMorphable,
        public readonly bool $appendable,
        public readonly bool $includeable,
        public readonly bool $responsable,
        public readonly bool $transformable,
        public readonly bool $validateable,
        public readonly bool $wrappable,
        public readonly bool $emptyData,
        public readonly array $lifecycleMethods,
        public readonly bool $mergeValidationRules,
        public readonly bool $failOnUnknownFields,
        public readonly bool $stopOnFirstFailure,
        public readonly ?string $errorBag,
        public readonly ?string $redirect,
        public readonly ?string $redirectRoute,
        public readonly bool $plainTransform,
        public readonly bool $directArrayCreation,
        public readonly DataAttributesCollection $attributes,
        public readonly array $dataIterablePropertyAnnotations,
        public readonly array $outputMappedProperties,
    ) {
    }

    /**
     * Determine if the class declares a creation lifecycle method.
     */
    public function hasLifecycleMethod(string $method): bool
    {
        return isset($this->lifecycleMethods[$method]);
    }
}
