<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayAccess;
use JsonSerializable;
use LogicException;
use OutOfBoundsException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

abstract class DataObject implements ArrayAccess, JsonSerializable
{
    /**
     * Property map cache (class name => [snake_case key => camelCase property]).
     *
     * @var array<string,string>>
     */
    protected array $propertyMapCache = [];

    /**
     * Cache for the array representation of the object.
     */
    protected array $arrayCache = [];

    /**
     * Create an instance of the class using the provided data array.
     */
    public static function make(array $data): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();
        $constructorArgs = [];

        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();
            $dataKey = static::convertDataKeyToProperty($paramName);
            $dataValue = null;

            // check if the data key exists in the array
            // and convert the value to the correct type automatically
            if (array_key_exists($dataKey, $data)) {
                $dataValue = $data[$dataKey];
                $dataValue = static::convertValueToType($dataValue, $parameter);
            // use the default value if available
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dataValue = $parameter->getDefaultValue();
            } else {
                $dataValue = static::getDefaultValueForType($parameter);
            }

            $constructorArgs[$paramName] = $dataValue;
        }

        return new static(...$constructorArgs);
    }

    /**
     * Convert the parameter name to the data key format.
     * It converts camelCase to snake_case by default.
     */
    protected static function convertDataKeyToProperty(string $input): string
    {
        return Str::snake($input);
    }

    /**
     * Convert the property name to the data key format.
     * It converts snake_case to camelCase by default.
     */
    protected static function convertPropertyToDataKey(string $input): string
    {
        return Str::camel($input);
    }

    /**
     * Convert the value to the correct type based on the parameter type.
     */
    protected static function convertValueToType(mixed $value, ReflectionParameter $parameter): mixed
    {
        if (! $type = $parameter->getType()) {
            return $value;
        }

        if ($type instanceof ReflectionNamedType) {
            return match ($type->getName()) {
                'int' => (int) $value,
                'float' => (float) $value,
                'string' => (string) $value,
                'bool' => (bool) $value,
                'array' => is_array($value) ? $value : [$value],
                default => $value,
            };
        }

        return $value;
    }

    /**
     * Get default value for the parameter type.
     */
    protected static function getDefaultValueForType(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if (! $type || $type->allowsNull()) {
            return null;
        }

        throw new RuntimeException(
            "Missing required property `{$parameter->name}` in `" . static::class . '`'
        );
    }

    /**
     * Get property map (snake_case key => camelCase property).
     *
     * @return array<string, string>
     */
    protected function getPropertyMap(): array
    {
        if ($this->propertyMapCache) {
            return $this->propertyMapCache;
        }

        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $propName = $property->getName();
            $snakeKey = static::convertDataKeyToProperty($propName);
            $this->propertyMapCache[$snakeKey] = $propName;
        }

        return $this->propertyMapCache;
    }

    /**
     * Check if the offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->getPropertyMap());
    }

    /**
     * Get the value at the specified offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset]
            ?? throw new OutOfBoundsException("Undefined offset: {$offset}");
    }

    /**
     * Set the value at the specified offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Data object may not be mutated using array access.');
    }

    /**
     * Unset the value at the specified offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Data object may not be mutated using array access.');
    }

    /**
     * Convert the object to an array representation.
     */
    public function toArray(): array
    {
        if ($this->arrayCache) {
            return $this->arrayCache;
        }

        $result = [];
        $map = $this->getPropertyMap();

        foreach ($map as $snakeKey => $propName) {
            $value = $this->{$propName};
            // recursively convert nested objects to arrays
            if ($value instanceof self) {
                $value = $value->toArray();
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $value = $value->toArray();
            }

            $result[$snakeKey] = $value;
        }

        return $this->arrayCache = $result;
    }

    /**
     * JSON serialize the object.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
