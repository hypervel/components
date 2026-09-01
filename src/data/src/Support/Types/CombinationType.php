<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

abstract class CombinationType extends Type
{
    /**
     * Create a new combination type.
     *
     * @param non-empty-list<Type> $types
     */
    public function __construct(
        public readonly array $types,
    ) {
    }

    /**
     * Get the declared types and their inherited types.
     *
     * @return array<string, list<string>>
     */
    public function getAcceptedTypes(): array
    {
        $types = [];

        foreach ($this->types as $type) {
            foreach ($type->getAcceptedTypes() as $name => $acceptedTypes) {
                $types[$name] = $acceptedTypes;
            }
        }

        return $types;
    }

    /**
     * Get every named type in declaration order.
     *
     * @return list<NamedType>
     */
    public function getNamedTypes(): array
    {
        $types = [];

        foreach ($this->types as $type) {
            array_push($types, ...$type->getNamedTypes());
        }

        return $types;
    }
}
