<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayAccess;
use ArrayObject;
use Hypervel\Support\Traits\Macroable;

class Optional implements ArrayAccess
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The underlying object.
     */
    protected mixed $value;

    /**
     * Create a new optional instance.
     */
    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * Dynamically access a property on the underlying object.
     */
    public function __get(string $key): mixed
    {
        if (is_object($this->value)) {
            return $this->value->{$key} ?? null;
        }

        return null;
    }

    /**
     * Dynamically check a property exists on the underlying object.
     */
    public function __isset(mixed $name): bool
    {
        if ($this->value instanceof ArrayObject || is_array($this->value)) {
            return isset($this->value[$name]);
        }

        if (is_object($this->value)) {
            return isset($this->value->{$name});
        }

        return false;
    }

    /**
     * Determine if an item exists at an offset.
     */
    public function offsetExists(mixed $key): bool
    {
        return Arr::accessible($this->value) && Arr::exists($this->value, $key);
    }

    /**
     * Get an item at a given offset.
     */
    public function offsetGet(mixed $key): mixed
    {
        return Arr::get($this->value, $key);
    }

    /**
     * Set the item at a given offset.
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        if (Arr::accessible($this->value)) {
            $this->value[$key] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     */
    public function offsetUnset(mixed $key): void
    {
        if (Arr::accessible($this->value)) {
            unset($this->value[$key]);
        }
    }

    /**
     * Dynamically pass a method to the underlying object.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (is_object($this->value)) {
            return $this->value->{$method}(...$parameters);
        }

        return null;
    }
}
