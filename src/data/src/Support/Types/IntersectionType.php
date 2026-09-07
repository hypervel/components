<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

class IntersectionType extends CombinationType
{
    /**
     * Determine if this declaration accepts the given type name.
     */
    public function acceptsType(string $type): bool
    {
        foreach ($this->types as $subType) {
            if (! $subType->acceptsType($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if this declaration accepts the given value.
     */
    public function acceptsValue(mixed $value): bool
    {
        foreach ($this->types as $subType) {
            if (! $subType->acceptsValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if every value accepted by this declaration is an instance of the given type.
     */
    public function guaranteesType(string $type): bool
    {
        foreach ($this->types as $subType) {
            if ($subType->guaranteesType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the declared type accepted by a base type.
     */
    public function findAcceptedTypeForBaseType(string $class): ?string
    {
        return $this->acceptsType($class) ? $class : null;
    }
}
