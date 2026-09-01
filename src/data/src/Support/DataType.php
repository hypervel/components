<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\Type;

class DataType
{
    /**
     * Create a new data type.
     */
    public function __construct(
        public readonly Type $type,
        public readonly bool $isNullable,
        public readonly bool $isMixed,
    ) {
    }

    /**
     * Find the declared type accepted by a base type.
     */
    public function findAcceptedTypeForBaseType(string $class): ?string
    {
        return $this->type->findAcceptedTypeForBaseType($class);
    }

    /**
     * Determine if this declaration accepts the given type name.
     */
    public function acceptsType(string $type): bool
    {
        if ($this->isMixed) {
            return true;
        }

        return $this->type->acceptsType($type);
    }

    /**
     * Get the declared types and their inherited types.
     *
     * @return array<string, list<string>>
     */
    public function getAcceptedTypes(): array
    {
        if ($this->isMixed) {
            return [];
        }

        return $this->type->getAcceptedTypes();
    }

    /**
     * Get every named type in declaration order.
     *
     * @return list<NamedType>
     */
    public function getNamedTypes(): array
    {
        return $this->type->getNamedTypes();
    }

    /**
     * Determine if this declaration accepts the given value.
     */
    public function acceptsValue(mixed $value): bool
    {
        if ($this->isMixed) {
            return true;
        }

        if ($this->isNullable && $value === null) {
            return true;
        }

        return $this->type->acceptsValue($value);
    }
}
