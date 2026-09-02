<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Transformation;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\EmptyData;
use Hypervel\Data\Exceptions\DataPropertyCanOnlyHaveOneType;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataParameter;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Types\NamedType;
use Traversable;

class EmptyDataResolver
{
    /**
     * Create an empty data resolver.
     */
    public function __construct(protected readonly DataClassRepository $dataClasses)
    {
    }

    /**
     * Resolve the empty representation of a data class.
     *
     * @param class-string<BaseData> $class
     */
    public function execute(
        string $class,
        array $extra = [],
        mixed $defaultReturnValue = null,
    ): array {
        $dataClass = $this->dataClasses->get($class);

        $payload = [];

        foreach ($dataClass->properties as $property) {
            $name = $property->outputMappedName ?? $property->name;

            if ($property->hasDefaultValue) {
                $payload[$name] = $this->getDefaultValue($dataClass, $property);
            } else {
                $payload[$name] = array_key_exists($property->name, $extra)
                    ? $extra[$property->name]
                    : $this->getValueForProperty($property, $defaultReturnValue);
            }
        }

        return $payload;
    }

    /**
     * Get a declared default without retaining it in metadata.
     */
    protected function getDefaultValue(DataClass $dataClass, DataProperty $property): mixed
    {
        if (! $property->isConstructorParameter) {
            return $property->reflection->getDefaultValue();
        }

        /** @var DataParameter $parameter */
        $parameter = array_find(
            $dataClass->constructorParameters,
            fn (DataParameter $parameter): bool => $parameter->name === $property->name,
        );

        return $parameter->reflection->getDefaultValue();
    }

    /**
     * Resolve an empty value from one property declaration.
     */
    protected function getValueForProperty(
        DataProperty $property,
        mixed $defaultReturnValue = null,
    ): mixed {
        $propertyType = $property->type;

        if ($propertyType->isMixed) {
            return $defaultReturnValue;
        }

        $types = array_values(array_filter(
            $propertyType->getNamedTypes(),
            static fn (NamedType $type): bool => $type->name !== 'null'
                && $type->name !== Optional::class
                && ! is_a($type->name, Lazy::class, true),
        ));

        if ($types === []) {
            return $defaultReturnValue;
        }

        if (count($types) > 1) {
            throw DataPropertyCanOnlyHaveOneType::create($property);
        }

        $type = $types[0];

        if ($type->acceptsType('array')) {
            return [];
        }

        if ($type->kind->isDataObject()
            && $type->dataClass !== null
            && $this->dataClasses->get($type->dataClass)->emptyData
        ) {
            /** @var class-string<EmptyData> $dataClass */
            $dataClass = $type->dataClass;

            return $dataClass::empty();
        }

        if ($type->kind->isDataCollectable()) {
            return [];
        }

        if ($propertyType->findAcceptedTypeForBaseType(Traversable::class) !== null) {
            return [];
        }

        return $defaultReturnValue;
    }
}
