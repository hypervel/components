<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Factories;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\AutoWhenLoadedLazy;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\GetsCast;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\WithCastAndTransformer;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Enums\DataPropertyOperation;
use Hypervel\Data\Exceptions\InvalidDataDeclaration;
use Hypervel\Data\Mappers\NameMapper;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataParameter;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\DataPropertyType;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use PropertyHookType;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

class DataPropertyFactory
{
    /**
     * Create a new data property factory.
     */
    public function __construct(
        protected readonly DataTypeFactory $typeFactory,
        protected readonly DataConfig $config,
        protected readonly NameMapperResolver $nameMapperResolver,
    ) {
    }

    /**
     * Build an immutable property definition.
     *
     * @param ReflectionClass<object> $reflectionClass
     * @param list<DataIterableAnnotation> $classDefinedDataIterableAnnotations
     * @param null|ReflectionAttribute<object> $classAutoLazy
     */
    public function build(
        ReflectionProperty $reflectionProperty,
        ReflectionClass $reflectionClass,
        ?DataParameter $constructorParameter = null,
        ?NameMapper $classInputNameMapper = null,
        ?NameMapper $classOutputNameMapper = null,
        array $classDefinedDataIterableAnnotations = [],
        ?ReflectionAttribute $classAutoLazy = null,
    ): DataProperty {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty($reflectionProperty);

        $type = $this->typeFactory->buildProperty(
            $reflectionProperty->getType(),
            $reflectionClass,
            $reflectionProperty,
            $attributes,
            $classDefinedDataIterableAnnotations,
        );

        $inputMappedName = $this->nameMapperResolver
            ->resolveInput($attributes, $classInputNameMapper)
            ?->map($reflectionProperty->name);
        $outputMappedName = $this->nameMapperResolver
            ->resolveOutput($attributes, $classOutputNameMapper)
            ?->map($reflectionProperty->name);

        if ($constructorParameter !== null) {
            $hasDefaultValue = $constructorParameter->hasDefaultValue;
            $defaultValue = $hasDefaultValue
                ? $constructorParameter->reflection->getDefaultValue()
                : null;
        } else {
            $hasDefaultValue = $reflectionProperty->hasDefaultValue();
            $defaultValue = $hasDefaultValue ? $reflectionProperty->getDefaultValue() : null;
        }

        if ($hasDefaultValue && $defaultValue instanceof Optional) {
            $hasDefaultValue = false;
        }

        $autoLazy = $attributes->first(AutoLazy::class);

        if ($classAutoLazy !== null && $type->lazyType !== null && $autoLazy === null) {
            $autoLazy = $classAutoLazy;
        }

        $isVirtual = $reflectionProperty->isVirtual();
        $computed = $attributes->has(Computed::class) || $isVirtual;
        $configuredCasts = $this->applicableExtensions($type, $this->config->casts);
        $configuredTransformers = $this->applicableExtensions($type, $this->config->transformers);
        [$constructionOperation, $constructionTarget] = $this->resolveConstructionOperation($type, $computed);

        $property = new DataProperty(
            name: $reflectionProperty->name,
            className: $reflectionProperty->class,
            type: $type,
            constructionOperation: $constructionOperation,
            constructionTarget: $constructionTarget,
            transformationOperation: $this->resolveTransformationOperation($type),
            validate: ! $computed
                && $constructorParameter?->contextualAttribute === null
                && ! $attributes->has(AutoWhenLoadedLazy::class)
                && ! $attributes->has(WithoutValidation::class),
            computed: $computed,
            hidden: $attributes->has(Hidden::class),
            isPromoted: $reflectionProperty->isPromoted(),
            isConstructorParameter: $constructorParameter !== null,
            isReadonly: $reflectionProperty->isReadOnly(),
            hasGetHook: $reflectionProperty->hasHook(PropertyHookType::Get),
            morphable: $attributes->has(PropertyForMorph::class),
            loadRelation: $attributes->has(LoadRelation::class),
            autoLazy: $autoLazy,
            hasDefaultValue: $hasDefaultValue,
            cast: $attributes->first(GetsCast::class),
            transformer: $attributes->first(WithTransformer::class)
                ?? $attributes->first(WithCastAndTransformer::class),
            inputMappedName: $inputMappedName,
            inputMappedPath: $inputMappedName === null
                ? null
                : (is_int($inputMappedName) ? [$inputMappedName] : explode('.', $inputMappedName)),
            outputMappedName: $outputMappedName,
            configuredCasts: $configuredCasts,
            configuredTransformers: $configuredTransformers,
            attributes: $attributes,
            reflection: $reflectionProperty,
        );

        foreach ($type->getIterableTypes() as $iterableType) {
            if (is_a($iterableType->name, EloquentCollection::class, true)
                && ! $iterableType->iterableItemType->guaranteesType(Model::class)) {
                throw InvalidDataDeclaration::invalidEloquentCollectionItemType(
                    $reflectionClass->getName(),
                    $iterableType->name,
                    $property,
                );
            }
        }

        return $property;
    }

    /**
     * Resolve the fixed construction operation for a property declaration.
     *
     * Classification stops at the first conversion family present, matching the general
     * engine's priority. Copy delegates ambiguous or custom conversion to that engine.
     *
     * @return array{DataPropertyOperation, null|string}
     */
    protected function resolveConstructionOperation(
        DataPropertyType $type,
        bool $computed,
    ): array {
        if ($computed) {
            return [DataPropertyOperation::Copy, null];
        }

        $dataObjectTypes = $type->getDataObjectTypes();

        if ($dataObjectTypes !== []) {
            return count($dataObjectTypes) === 1
                ? [DataPropertyOperation::Data, $dataObjectTypes[0]->dataClass]
                : [DataPropertyOperation::Copy, null];
        }

        foreach ($type->getNamedTypes() as $namedType) {
            if ($namedType->isCastable) {
                return [DataPropertyOperation::Copy, null];
            }
        }

        $dateTypes = $this->acceptedTypes($type, DateTimeInterface::class);

        if ($dateTypes !== []) {
            return count($dateTypes) === 1
                ? [DataPropertyOperation::Date, $dateTypes[0]]
                : [DataPropertyOperation::Copy, null];
        }

        $enumTypes = $this->acceptedTypes($type, BackedEnum::class);

        if ($enumTypes !== []) {
            return count($enumTypes) === 1
                ? [DataPropertyOperation::Enum, $enumTypes[0]]
                : [DataPropertyOperation::Copy, null];
        }

        if (($target = $type->type->getSingleBuiltinType()) !== null) {
            return [DataPropertyOperation::Builtin, $target];
        }

        return [DataPropertyOperation::Copy, null];
    }

    /**
     * Resolve the fixed transformation operation for a property declaration.
     */
    protected function resolveTransformationOperation(DataPropertyType $type): ?DataPropertyOperation
    {
        $operations = [];
        $targets = [];

        foreach ($type->getNamedTypes() as $namedType) {
            $operation = match (true) {
                $namedType->kind->isDataObject() => DataPropertyOperation::Data,
                ! $namedType->builtIn && is_a($namedType->name, DateTimeInterface::class, true) => DataPropertyOperation::Date,
                ! $namedType->builtIn && is_a($namedType->name, BackedEnum::class, true) => DataPropertyOperation::Enum,
                $namedType->builtIn && in_array($namedType->name, [
                    'array',
                    'bool',
                    'false',
                    'float',
                    'int',
                    'null',
                    'string',
                    'true',
                ], true) => DataPropertyOperation::Copy,
                default => null,
            };

            if ($operation === null) {
                return null;
            }

            if ($operation !== DataPropertyOperation::Copy) {
                $operations[$operation->name] = $operation;
                $targets[$operation->name][$namedType->name] = true;
            }
        }

        if (count($operations) > 1) {
            return null;
        }

        $operation = $operations === []
            ? DataPropertyOperation::Copy
            : array_values($operations)[0];

        return count($targets[$operation->name] ?? []) > 1 ? null : $operation;
    }

    /**
     * Find the declared types accepted by a base class.
     *
     * @return list<string>
     */
    protected function acceptedTypes(DataPropertyType $type, string $baseType): array
    {
        $types = [];

        foreach ($type->getNamedTypes() as $namedType) {
            if ($namedType->builtIn || ! is_a($namedType->name, $baseType, true)) {
                continue;
            }

            $types[$namedType->name] = $namedType->name;
        }

        return array_values($types);
    }

    /**
     * Select configured extensions that apply to a property type.
     *
     * @template TExtension of object
     *
     * @param array<string, class-string<TExtension>> $extensions
     * @return list<class-string<TExtension>>
     */
    protected function applicableExtensions(DataPropertyType $type, array $extensions): array
    {
        $applicable = [];

        foreach ($extensions as $baseType => $extension) {
            if ($type->findAcceptedTypeForBaseType($baseType) !== null) {
                $applicable[] = $extension;
            }
        }

        return array_values(array_unique($applicable));
    }
}
