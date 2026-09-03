<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Factories;

use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\MergeValidationRules;
use Hypervel\Data\Contracts\AppendableData;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\EmptyData;
use Hypervel\Data\Contracts\IncludeableData;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Contracts\ValidateableData;
use Hypervel\Data\Contracts\WrappableData;
use Hypervel\Data\Data;
use Hypervel\Data\Dto;
use Hypervel\Data\Enums\CustomCreationMethodType;
use Hypervel\Data\Exceptions\InvalidDataDeclaration;
use Hypervel\Data\Mappers\NameMapper;
use Hypervel\Data\Mappers\ProvidedNameMapper;
use Hypervel\Data\Resource;
use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataMethod;
use Hypervel\Data\Support\DataParameter;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Foundation\Http\Attributes\ErrorBag;
use Hypervel\Foundation\Http\Attributes\FailOnUnknownFields;
use Hypervel\Foundation\Http\Attributes\RedirectTo;
use Hypervel\Foundation\Http\Attributes\RedirectToRoute;
use Hypervel\Foundation\Http\Attributes\StopOnFirstFailure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class DataClassFactory
{
    /**
     * Create a new data class factory.
     */
    public function __construct(
        protected readonly DataPropertyFactory $propertyFactory,
        protected readonly DataMethodFactory $methodFactory,
        protected readonly DataParameterFactory $parameterFactory,
        protected readonly DataIterableAnnotationReader $iterableAnnotationReader,
        protected readonly NameMapperResolver $nameMapperResolver,
        protected readonly DataConfig $config,
    ) {
    }

    /**
     * Build immutable metadata for a data class.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    public function build(ReflectionClass $reflectionClass): DataClass
    {
        /** @var class-string<BaseData> $name */
        $name = $reflectionClass->getName();
        $attributes = DataAttributesCollectionFactory::buildFromReflectionClass($reflectionClass);
        $constructor = $reflectionClass->getConstructor();
        $constructorParameters = $this->resolveConstructorParameters($reflectionClass, $constructor);
        $contextualParameters = $this->resolveContextualParameters($constructorParameters);
        $reflectionProperties = $this->resolveReflectionProperties($reflectionClass);

        $this->validateConstructorParameters($name, $constructorParameters, $reflectionProperties);

        $classInputNameMapper = $this->nameMapperResolver->resolveInput(
            $attributes,
            $this->nameMapperResolver->resolveConfigured($this->config->inputNameMapper),
        );
        $classOutputNameMapper = $this->nameMapperResolver->resolveOutput(
            $attributes,
            $this->nameMapperResolver->resolveConfigured($this->config->outputNameMapper),
        );

        if ($classInputNameMapper instanceof ProvidedNameMapper) {
            $classInputNameMapper = null;
        }

        if ($classOutputNameMapper instanceof ProvidedNameMapper) {
            $classOutputNameMapper = null;
        }

        [$properties, $iterableAnnotations] = $this->resolveProperties(
            $name,
            $reflectionClass,
            $reflectionProperties,
            $constructor,
            $constructorParameters,
            $classInputNameMapper,
            $classOutputNameMapper,
            $attributes->first(AutoLazy::class),
        );

        $failOnUnknownFields = $attributes->first(FailOnUnknownFields::class)?->newInstance();
        $errorBag = $attributes->first(ErrorBag::class)?->newInstance();
        $redirect = $attributes->first(RedirectTo::class)?->newInstance();
        $redirectRoute = $attributes->first(RedirectToRoute::class)?->newInstance();
        $lifecycleMethods = $this->resolveLifecycleMethods($reflectionClass);
        $propertyMorphable = $reflectionClass->implementsInterface(PropertyMorphableData::class);

        return new DataClass(
            name: $name,
            properties: $properties,
            methods: $this->resolveMethods($reflectionClass),
            constructor: $constructor,
            constructorParameters: array_values($constructorParameters),
            contextualParameters: $contextualParameters,
            isReadonly: $reflectionClass->isReadOnly(),
            isAbstract: $reflectionClass->isAbstract(),
            isFinal: $reflectionClass->isFinal(),
            propertyMorphable: $propertyMorphable,
            appendable: $reflectionClass->implementsInterface(AppendableData::class),
            includeable: $reflectionClass->implementsInterface(IncludeableData::class),
            responsable: $reflectionClass->implementsInterface(ResponsableData::class),
            transformable: $reflectionClass->implementsInterface(TransformableData::class),
            validateable: $reflectionClass->implementsInterface(ValidateableData::class),
            wrappable: $reflectionClass->implementsInterface(WrappableData::class),
            emptyData: $reflectionClass->implementsInterface(EmptyData::class),
            lifecycleMethods: $lifecycleMethods,
            mergeValidationRules: $attributes->has(MergeValidationRules::class),
            failOnUnknownFields: $failOnUnknownFields->value ?? false,
            stopOnFirstFailure: $attributes->has(StopOnFirstFailure::class),
            errorBag: $errorBag?->name,
            redirect: $redirect?->url,
            redirectRoute: $redirectRoute?->route,
            plainTransform: $this->isPlainTransform($properties),
            directArrayCreation: $this->supportsDirectArrayCreation(
                $reflectionClass,
                $contextualParameters,
                $properties,
                $lifecycleMethods,
                $propertyMorphable,
            ),
            directConstructorInstantiation: $this->supportsDirectConstructorInstantiation(
                $reflectionClass,
                $constructor,
                $contextualParameters,
                $properties,
            ),
            attributes: $attributes,
            dataIterablePropertyAnnotations: $iterableAnnotations,
            outputMappedProperties: $this->validateMappings($name, $properties),
        );
    }

    /**
     * Build constructor parameter metadata keyed by parameter name.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @return array<string, DataParameter>
     */
    protected function resolveConstructorParameters(
        ReflectionClass $reflectionClass,
        ?ReflectionMethod $constructor,
    ): array {
        if ($constructor === null) {
            return [];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[$parameter->name] = $this->parameterFactory->build($parameter, $reflectionClass);
        }

        return $parameters;
    }

    /**
     * Get constructor parameters resolved from contextual attributes.
     *
     * @param array<string, DataParameter> $parameters
     * @return array<string, true>
     */
    protected function resolveContextualParameters(array $parameters): array
    {
        $contextualParameters = [];

        // Keep both forms together; declaration validation prevents constructor-only names from colliding with data properties.
        foreach ($parameters as $parameter) {
            if ($parameter->contextualAttribute !== null) {
                $contextualParameters[$parameter->name] = true;
            }
        }

        return $contextualParameters;
    }

    /**
     * Get public, non-static data properties keyed by name.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @return array<string, ReflectionProperty>
     */
    protected function resolveReflectionProperties(ReflectionClass $reflectionClass): array
    {
        $properties = [];

        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $properties[$property->name] = $property;
            }
        }

        return $properties;
    }

    /**
     * Validate that constructor inputs have one supported ownership form.
     *
     * @param class-string<BaseData> $class
     * @param array<string, DataParameter> $parameters
     * @param array<string, ReflectionProperty> $properties
     */
    protected function validateConstructorParameters(
        string $class,
        array $parameters,
        array $properties,
    ): void {
        foreach ($parameters as $parameter) {
            if ($parameter->isPromoted && ! isset($properties[$parameter->name])) {
                throw InvalidDataDeclaration::nonPublicPromotedProperty($class, $parameter);
            }

            if (! $parameter->isPromoted
                && $parameter->contextualAttribute === null
                && ! isset($properties[$parameter->name])) {
                throw InvalidDataDeclaration::missingDataProperty($class, $parameter);
            }
        }
    }

    /**
     * Build data properties and their selected iterable annotations.
     *
     * @param class-string<BaseData> $class
     * @param ReflectionClass<object> $reflectionClass
     * @param array<string, ReflectionProperty> $reflectionProperties
     * @param array<string, DataParameter> $constructorParameters
     * @param null|ReflectionAttribute<AutoLazy> $classAutoLazy
     * @return array{array<string, DataProperty>, array<string, non-empty-list<DataIterableAnnotation>>}
     */
    protected function resolveProperties(
        string $class,
        ReflectionClass $reflectionClass,
        array $reflectionProperties,
        ?ReflectionMethod $constructor,
        array $constructorParameters,
        ?NameMapper $classInputNameMapper,
        ?NameMapper $classOutputNameMapper,
        ?ReflectionAttribute $classAutoLazy,
    ): array {
        $constructorAnnotations = $constructor === null
            ? []
            : $this->iterableAnnotationReader->getForMethod($constructor);
        $classAnnotations = $this->resolveClassAnnotations($reflectionClass);
        $properties = [];
        $selectedAnnotations = [];

        foreach ($reflectionProperties as $name => $reflectionProperty) {
            $parameter = $constructorParameters[$name] ?? null;
            $constructorParameter = $parameter !== null
                && ($parameter->isPromoted || $parameter->contextualAttribute === null)
                    ? $parameter
                    : null;
            $propertyAnnotations = $this->iterableAnnotationReader->getForProperty($reflectionProperty);
            $annotations = $constructorParameter === null
                ? []
                : ($constructorAnnotations[$name] ?? []);

            if ($annotations === []) {
                $annotations = $propertyAnnotations !== []
                    ? $propertyAnnotations
                    : ($classAnnotations[$name] ?? []);
            }

            $property = $this->propertyFactory->build(
                reflectionProperty: $reflectionProperty,
                reflectionClass: $reflectionClass,
                constructorParameter: $constructorParameter,
                classInputNameMapper: $classInputNameMapper,
                classOutputNameMapper: $classOutputNameMapper,
                classDefinedDataIterableAnnotations: $annotations,
                classAutoLazy: $classAutoLazy,
            );

            if ($parameter?->contextualAttribute !== null && ! $parameter->isPromoted) {
                throw InvalidDataDeclaration::contextualParameterConflictsWithProperty(
                    $class,
                    $parameter,
                    $property,
                );
            }

            if ($property->computed && $property->isConstructorParameter) {
                throw InvalidDataDeclaration::computedConstructorProperty($class, $property);
            }

            // Backed set-only hooks remain readable through their backing storage.
            if ($reflectionProperty->isVirtual() && ! $property->hasGetHook) {
                throw InvalidDataDeclaration::writeOnlyProperty($class, $property);
            }

            if ($property->isReadonly && ! $property->isConstructorParameter && ! $property->computed) {
                throw InvalidDataDeclaration::unassignableReadonlyProperty($class, $property);
            }

            $properties[$name] = $property;

            if ($annotations !== []) {
                $selectedAnnotations[$name] = $annotations;
            }
        }

        return [$properties, $selectedAnnotations];
    }

    /**
     * Resolve nearest class-level iterable annotations across inheritance.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @return array<string, non-empty-list<DataIterableAnnotation>>
     */
    protected function resolveClassAnnotations(ReflectionClass $reflectionClass): array
    {
        $annotations = [];
        $current = $reflectionClass;

        while (! in_array($current->getName(), [Data::class, Dto::class, Resource::class], true)) {
            foreach ($this->iterableAnnotationReader->getForClass($current) as $property => $propertyAnnotations) {
                $annotations[$property] ??= $propertyAnnotations;
            }

            $parent = $current->getParentClass();

            if ($parent === false) {
                break;
            }

            $current = $parent;
        }

        return $annotations;
    }

    /**
     * Build named creation method metadata in declaration order.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @return array<string, DataMethod>
     */
    protected function resolveMethods(ReflectionClass $reflectionClass): array
    {
        $methods = [];

        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            if (! $reflectionMethod->isPublic()
                || ! $reflectionMethod->isStatic()
                || in_array($reflectionMethod->name, ['from', 'collect', 'collection'], true)
                || (! str_starts_with($reflectionMethod->name, 'from')
                    && ! str_starts_with($reflectionMethod->name, 'collect'))) {
                continue;
            }

            $method = $this->methodFactory->build($reflectionMethod, $reflectionClass);

            if ($method->customCreationMethodType !== CustomCreationMethodType::None) {
                $methods[$method->name] = $method;
            }
        }

        return $methods;
    }

    /**
     * Compile user-owned creation lifecycle method presence.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @return array<string, true>
     */
    protected function resolveLifecycleMethods(ReflectionClass $reflectionClass): array
    {
        $methods = [];

        foreach ([
            'authorize',
            'rules',
            'messages',
            'attributes',
            'withValidator',
            'after',
            'normalizers',
            'stopOnFirstFailure',
            'redirect',
            'redirectRoute',
            'errorBag',
        ] as $name) {
            if (! $reflectionClass->hasMethod($name)) {
                continue;
            }

            $declaringClass = $reflectionClass->getMethod($name)->getDeclaringClass()->getName();

            if (! in_array($declaringClass, [Data::class, Dto::class, Resource::class], true)) {
                $methods[$name] = true;
            }
        }

        return $methods;
    }

    /**
     * Validate mapping ownership and build the output reverse map.
     *
     * @param class-string<BaseData> $class
     * @param array<string, DataProperty> $properties
     * @return array<array-key, string>
     */
    protected function validateMappings(string $class, array $properties): array
    {
        $inputOwners = [];
        $outputOwners = [];
        $outputMappedProperties = [];

        foreach ($properties as $property) {
            $inputPaths = [$property->name];

            if ($property->inputMappedName !== null && $property->inputMappedName !== $property->name) {
                $inputPaths[] = $property->inputMappedName;
            }

            foreach ($inputPaths as $path) {
                if (isset($inputOwners[$path]) && $inputOwners[$path] !== $property) {
                    throw InvalidDataDeclaration::duplicateInputPath(
                        $class,
                        $path,
                        $inputOwners[$path],
                        $property,
                    );
                }

                $inputOwners[$path] = $property;
            }

            if ($property->hidden) {
                continue;
            }

            $outputKey = $property->outputMappedName ?? $property->name;

            if (isset($outputOwners[$outputKey]) && $outputOwners[$outputKey] !== $property) {
                throw InvalidDataDeclaration::duplicateOutputKey(
                    $class,
                    $outputKey,
                    $outputOwners[$outputKey],
                    $property,
                );
            }

            $outputOwners[$outputKey] = $property;

            if ($property->outputMappedName !== null) {
                $outputMappedProperties[$property->outputMappedName] = $property->name;
            }
        }

        return $outputMappedProperties;
    }

    /**
     * Determine if exact array values can bypass general construction.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @param array<string, true> $contextualParameters
     * @param array<string, DataProperty> $properties
     * @param array<string, true> $lifecycleMethods
     */
    protected function supportsDirectArrayCreation(
        ReflectionClass $reflectionClass,
        array $contextualParameters,
        array $properties,
        array $lifecycleMethods,
        bool $propertyMorphable,
    ): bool {
        if ($reflectionClass->isAbstract()
            || $propertyMorphable
            || isset($lifecycleMethods['normalizers'])
            || $this->config->normalizers !== []) {
            return false;
        }

        if ($contextualParameters !== []) {
            return false;
        }

        foreach ($properties as $property) {
            if ($property->autoLazy !== null
                || $property->loadRelation
                || $property->cast !== null
                || $property->configuredCasts !== []
                || $property->type->getDataCollectableTypes() !== []
                || $property->type->getIterableTypes() !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if exact array values can be spread directly into the constructor.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @param array<string, true> $contextualParameters
     * @param array<string, DataProperty> $properties
     */
    protected function supportsDirectConstructorInstantiation(
        ReflectionClass $reflectionClass,
        ?ReflectionMethod $constructor,
        array $contextualParameters,
        array $properties,
    ): bool {
        if ($reflectionClass->isAbstract()
            || ($constructor !== null && (! $constructor->isPublic() || $constructor->isVariadic()))
            || $contextualParameters !== []) {
            return false;
        }

        foreach ($properties as $property) {
            if (! $property->computed && ! $property->isConstructorParameter) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if declared values can be copied directly during transformation.
     *
     * @param array<string, DataProperty> $properties
     */
    protected function isPlainTransform(array $properties): bool
    {
        foreach ($properties as $property) {
            if ($property->hidden
                || $property->outputMappedName !== null
                || $property->transformer !== null
                || $property->configuredTransformers !== []
                || $property->type->lazyType !== null
                || $property->type->isOptional
                || $property->type->isMixed) {
                return false;
            }

            foreach ($property->type->getNamedTypes() as $type) {
                if (! $type->builtIn
                    || $type->kind->isNonDataIterable()
                    || $type->kind->isDataRelated()
                    || $type->name === 'object') {
                    return false;
                }
            }
        }

        return true;
    }
}
