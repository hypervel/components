<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\Type;

class DataPropertyType extends DataType
{
    /**
     * The declared data object types.
     *
     * @var list<NamedType>
     */
    protected readonly array $dataObjectTypes;

    protected readonly ?NamedType $dataObjectType;

    /**
     * The declared data collection types.
     *
     * @var list<NamedType>
     */
    protected readonly array $dataCollectableTypes;

    protected readonly ?NamedType $dataCollectableType;

    /**
     * The declared iterable types with item metadata.
     *
     * @var list<NamedType>
     */
    protected readonly array $iterableTypes;

    protected readonly ?NamedType $nonDataIterableType;

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

        $dataObjectTypes = [];
        $dataCollectableTypes = [];
        $iterableTypes = [];
        $nonDataIterableTypes = [];

        foreach ($this->getNamedTypes() as $namedType) {
            if ($namedType->kind->isDataObject()) {
                $dataObjectTypes[] = $namedType;
            }

            if ($namedType->kind->isDataCollectable()) {
                $dataCollectableTypes[] = $namedType;
            }

            if ($namedType->iterableItemType !== null) {
                $iterableTypes[] = $namedType;

                if (! $namedType->kind->isDataCollectable()) {
                    $nonDataIterableTypes[] = $namedType;
                }
            }
        }

        $this->dataObjectTypes = $dataObjectTypes;
        $this->dataObjectType = count($dataObjectTypes) === 1 ? $dataObjectTypes[0] : null;
        $this->dataCollectableTypes = $dataCollectableTypes;
        $this->dataCollectableType = count($dataCollectableTypes) === 1 ? $dataCollectableTypes[0] : null;
        $this->iterableTypes = $iterableTypes;
        $this->nonDataIterableType = count($nonDataIterableTypes) === 1 ? $nonDataIterableTypes[0] : null;
    }

    /**
     * Get the declared data object types.
     *
     * @return list<NamedType>
     */
    public function getDataObjectTypes(): array
    {
        return $this->dataObjectTypes;
    }

    /**
     * Get the one unambiguous declared data object type.
     */
    public function getDataObjectType(): ?NamedType
    {
        return $this->dataObjectType;
    }

    /**
     * Get the one unambiguous declared data object class.
     *
     * @return null|class-string<BaseData>
     */
    public function getDataObjectClass(): ?string
    {
        return $this->dataObjectType?->dataClass;
    }

    /**
     * Get the declared data collection types.
     *
     * @return list<NamedType>
     */
    public function getDataCollectableTypes(): array
    {
        return $this->dataCollectableTypes;
    }

    /**
     * Get the one unambiguous declared data collection type.
     */
    public function getDataCollectableType(): ?NamedType
    {
        return $this->dataCollectableType;
    }

    /**
     * Get the declared iterable types with item metadata.
     *
     * @return list<NamedType>
     */
    public function getIterableTypes(): array
    {
        return $this->iterableTypes;
    }

    /**
     * Get the one unambiguous non-data iterable with item metadata.
     */
    public function getNonDataIterableType(): ?NamedType
    {
        return $this->nonDataIterableType;
    }
}
