<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
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
     * The declared data collection and plain iterable container types.
     *
     * @var list<NamedType>
     */
    protected readonly array $containerTypes;

    /**
     * Whether more than one declared type can hold a supplied value; null, Optional, and Lazy are not counted.
     */
    public readonly bool $isUnion;

    /**
     * Whether more than one declared data collection or plain iterable type can hold an iterable value.
     */
    public readonly bool $hasContainerUnion;

    /**
     * Whether a data object or data collection type is declared.
     */
    public readonly bool $isDataRelated;

    /**
     * Whether creation records the declared type a value selects.
     *
     * A data value is replaced by its normalized input, which another type of the union could then
     * appear to accept, so casting and validation follow the type selected from the raw value.
     */
    public readonly bool $recordsSelectedType;

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
        $containerTypes = [];
        $valueTypes = 0;

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

            if ($namedType->kind->isDataCollectable() || $namedType->kind->isNonDataIterable()) {
                $containerTypes[] = $namedType;
            }

            $holdsValues = $namedType->builtIn
                ? $namedType->name !== 'null'
                : ! is_a($namedType->name, Optional::class, true) && ! is_a($namedType->name, Lazy::class, true);

            if ($holdsValues) {
                ++$valueTypes;
            }
        }

        $this->dataObjectTypes = $dataObjectTypes;
        $this->dataObjectType = count($dataObjectTypes) === 1 ? $dataObjectTypes[0] : null;
        $this->dataCollectableTypes = $dataCollectableTypes;
        $this->dataCollectableType = count($dataCollectableTypes) === 1 ? $dataCollectableTypes[0] : null;
        $this->iterableTypes = $iterableTypes;
        $this->nonDataIterableType = count($nonDataIterableTypes) === 1 ? $nonDataIterableTypes[0] : null;
        $this->containerTypes = $containerTypes;
        $this->isUnion = $valueTypes > 1;
        $this->hasContainerUnion = count($containerTypes) > 1;
        $this->isDataRelated = $dataObjectTypes !== [] || $dataCollectableTypes !== [];
        $this->recordsSelectedType = $this->hasContainerUnion || ($this->isUnion && $dataObjectTypes !== []);
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

    /**
     * Get the declared data collection and plain iterable container types.
     *
     * @return list<NamedType>
     */
    public function getContainerTypes(): array
    {
        return $this->containerTypes;
    }

    /**
     * Get the one declared container type whose container accepts a value.
     *
     * Null means no container type accepts the value, or more than one does.
     */
    public function acceptingContainerType(mixed $value): ?NamedType
    {
        $accepting = null;

        foreach ($this->containerTypes as $type) {
            if (! $type->acceptsValue($value)) {
                continue;
            }

            if ($accepting !== null) {
                return null;
            }

            $accepting = $type;
        }

        return $accepting;
    }
}
