<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

abstract class Type
{
    /**
     * Determine if this declaration accepts the given type name.
     */
    abstract public function acceptsType(string $type): bool;

    /**
     * Determine if this declaration accepts the given value.
     */
    abstract public function acceptsValue(mixed $value): bool;

    /**
     * Determine if every value accepted by this declaration is an instance of the given type.
     */
    abstract public function guaranteesType(string $type): bool;

    /**
     * Find the declared type accepted by a base type.
     */
    abstract public function findAcceptedTypeForBaseType(string $class): ?string;

    /**
     * Get every named type in declaration order.
     *
     * @return list<NamedType>
     */
    abstract public function getNamedTypes(): array;

    /**
     * Get the one unambiguous built-in type in this declaration.
     *
     * @return null|'array'|'bool'|'float'|'int'|'string'
     */
    abstract public function getSingleBuiltinType(): ?string;
}
