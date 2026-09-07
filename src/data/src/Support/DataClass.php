<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use Hypervel\Data\Support\Creation\DataCreationRecipe;
use Hypervel\Data\Support\Transformation\DataTransformationRecipe;
use ReflectionMethod;

/**
 * Immutable construction and transformation metadata for one data class.
 */
final readonly class DataClass
{
    /**
     * Create a new data class definition.
     *
     * Contextual parameter names include promoted and constructor-only forms.
     * Declaration validation prevents constructor-only names from colliding with data properties.
     * Bulk-copy transformation and fixed transformation recipes are mutually exclusive;
     * when both are absent, transformation uses the general property loop.
     *
     * @param class-string<BaseData> $name
     * @param array<string, DataProperty> $properties
     * @param array<string, DataMethod> $methods
     * @param list<DataParameter> $constructorParameters
     * @param array<string, true> $contextualParameters
     * @param array<string, true> $lifecycleMethods
     * @param array<string, non-empty-list<DataIterableAnnotation>> $dataIterablePropertyAnnotations
     * @param array<array-key, string> $outputMappedProperties
     */
    public function __construct(
        public string $name,
        public array $properties,
        public array $methods,
        public ?ReflectionMethod $constructor,
        public array $constructorParameters,
        public array $contextualParameters,
        public bool $isReadonly,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $propertyMorphable,
        public bool $appendable,
        public bool $includeable,
        public bool $responsable,
        public bool $transformable,
        public bool $validateable,
        public bool $wrappable,
        public bool $emptyData,
        public array $lifecycleMethods,
        public bool $mergeValidationRules,
        public bool $failOnUnknownFields,
        public bool $stopOnFirstFailure,
        public ?string $errorBag,
        public ?string $redirect,
        public ?string $redirectRoute,
        public bool $bulkCopyTransformation,
        public ?DataTransformationRecipe $transformationRecipe,
        public ?DataCreationRecipe $creationRecipe,
        public bool $directConstructorInstantiation,
        public DataAttributesCollection $attributes,
        public array $dataIterablePropertyAnnotations,
        public array $outputMappedProperties,
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
