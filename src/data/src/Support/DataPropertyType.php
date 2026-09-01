<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Lazy;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\Type;

class DataPropertyType extends DataType
{
    /**
     * Create a new data property type.
     *
     * @param null|class-string<Lazy> $lazyType
     */
    public function __construct(
        Type $type,
        public readonly bool $isOptional,
        bool $isNullable,
        bool $isMixed,
        public readonly ?string $lazyType,
    ) {
        parent::__construct($type, $isNullable, $isMixed);
    }

    /**
     * Get the declared data object types.
     *
     * @return list<NamedType>
     */
    public function getDataObjectTypes(): array
    {
        return array_values(array_filter(
            $this->getNamedTypes(),
            fn (NamedType $type): bool => $type->kind->isDataObject(),
        ));
    }

    /**
     * Get the one unambiguous declared data object type.
     */
    public function getDataObjectType(): ?NamedType
    {
        $types = $this->getDataObjectTypes();

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * Get the declared data collection types.
     *
     * @return list<NamedType>
     */
    public function getDataCollectableTypes(): array
    {
        return array_values(array_filter(
            $this->getNamedTypes(),
            fn (NamedType $type): bool => $type->kind->isDataCollectable(),
        ));
    }

    /**
     * Get the one unambiguous declared data collection type.
     */
    public function getDataCollectableType(): ?NamedType
    {
        $types = $this->getDataCollectableTypes();

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * Get the declared iterable types with item metadata.
     *
     * @return list<NamedType>
     */
    public function getIterableTypes(): array
    {
        return array_values(array_filter(
            $this->getNamedTypes(),
            fn (NamedType $type): bool => $type->iterableItemType !== null,
        ));
    }
}
