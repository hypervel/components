<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Types;

use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Enums\DataTypeKind;
use Traversable;

class NamedType extends Type
{
    public readonly bool $isCastable;

    /**
     * Create a new named type.
     *
     * @param null|class-string<BaseData> $dataClass
     * @param null|class-string|literal-string $iterableClass
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $builtIn,
        public readonly DataTypeKind $kind,
        public readonly ?string $dataClass = null,
        public readonly ?string $iterableClass = null,
        public readonly ?Type $iterableItemType = null,
    ) {
        $this->isCastable = ! $this->builtIn && is_a($this->name, Castable::class, true);
    }

    /**
     * Determine if this declaration accepts the given type name.
     */
    public function acceptsType(string $type): bool
    {
        if ($type === $this->name) {
            return true;
        }

        return match ($this->name) {
            'mixed' => true,
            'float' => $type === 'int',
            'bool' => $type === 'true' || $type === 'false',
            'object' => self::typeExists($type),
            'iterable' => $type === 'array' || is_a($type, Traversable::class, true),
            'callable' => self::typeExists($type) && method_exists($type, '__invoke'),
            default => ! $this->builtIn && is_a($type, $this->name, true),
        };
    }

    /**
     * Determine if this declaration accepts the given value.
     */
    public function acceptsValue(mixed $value): bool
    {
        return match ($this->name) {
            'mixed' => true,
            'null' => $value === null,
            'true' => $value === true,
            'false' => $value === false,
            'bool' => is_bool($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'void', 'never' => false,
            default => is_object($value) && is_a($value, $this->name),
        };
    }

    /**
     * Determine if every value accepted by this declaration is an instance of the given type.
     */
    public function guaranteesType(string $type): bool
    {
        return ! $this->builtIn && is_a($this->name, $type, true);
    }

    /**
     * Find the declared type accepted by a base type.
     */
    public function findAcceptedTypeForBaseType(string $class): ?string
    {
        if ($class === $this->name) {
            return $this->name;
        }

        if (! $this->builtIn && is_a($this->name, $class, true)) {
            return $this->name;
        }

        return null;
    }

    /**
     * Get every named type in declaration order.
     *
     * @return list<NamedType>
     */
    public function getNamedTypes(): array
    {
        return [$this];
    }

    /**
     * Get the one unambiguous built-in type in this declaration.
     *
     * @return null|'array'|'bool'|'float'|'int'|'string'
     */
    public function getSingleBuiltinType(): ?string
    {
        return match ($this->name) {
            'array', 'bool', 'float', 'int', 'string' => $this->name,
            default => null,
        };
    }

    /**
     * Determine if a class or interface type exists.
     */
    protected static function typeExists(string $type): bool
    {
        return class_exists($type) || interface_exists($type);
    }
}
