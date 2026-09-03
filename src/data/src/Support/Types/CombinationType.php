<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

abstract class CombinationType extends Type
{
    /**
     * The flattened named types in declaration order.
     *
     * @var list<NamedType>
     */
    protected readonly array $namedTypes;

    /**
     * The one unambiguous built-in type.
     *
     * @var null|'array'|'bool'|'float'|'int'|'string'
     */
    protected readonly ?string $singleBuiltinType;

    /**
     * Create a new combination type.
     *
     * @param non-empty-list<Type> $types
     */
    public function __construct(
        public readonly array $types,
    ) {
        $namedTypes = [];
        $singleBuiltinType = null;
        $builtinTypeCount = 0;

        foreach ($this->types as $type) {
            foreach ($type->getNamedTypes() as $namedType) {
                $namedTypes[] = $namedType;
                $builtinType = $namedType->getSingleBuiltinType();

                if ($builtinType !== null) {
                    $singleBuiltinType = $builtinType;
                    ++$builtinTypeCount;
                }
            }
        }

        $this->namedTypes = $namedTypes;
        $this->singleBuiltinType = $builtinTypeCount === 1 ? $singleBuiltinType : null;
    }

    /**
     * Get every named type in declaration order.
     *
     * @return list<NamedType>
     */
    public function getNamedTypes(): array
    {
        return $this->namedTypes;
    }

    /**
     * Get the one unambiguous built-in type in this declaration.
     *
     * @return null|'array'|'bool'|'float'|'int'|'string'
     */
    public function getSingleBuiltinType(): ?string
    {
        return $this->singleBuiltinType;
    }
}
