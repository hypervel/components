<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

class UnionType extends CombinationType
{
    /**
     * Determine if this declaration accepts the given type name.
     */
    public function acceptsType(string $type): bool
    {
        foreach ($this->types as $subType) {
            if ($subType->acceptsType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if this declaration accepts the given value.
     */
    public function acceptsValue(mixed $value): bool
    {
        foreach ($this->types as $subType) {
            if ($subType->acceptsValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if every value accepted by this declaration is an instance of the given type.
     */
    public function guaranteesType(string $type): bool
    {
        foreach ($this->types as $subType) {
            if (! $subType->guaranteesType($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find the declared type accepted by a base type.
     */
    public function findAcceptedTypeForBaseType(string $class): ?string
    {
        foreach ($this->types as $subType) {
            $found = $subType->findAcceptedTypeForBaseType($class);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
