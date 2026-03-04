<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources;

use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\Macroable;

trait DelegatesToResource
{
    use ForwardsCalls, Macroable {
        __call as macroCall;
    }

    /**
     * Get the value of the resource's route key.
     */
    public function getRouteKey(): mixed
    {
        return $this->resource->getRouteKey();
    }

    /**
     * Get the route key for the resource.
     */
    public function getRouteKeyName(): string
    {
        return $this->resource->getRouteKeyName();
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @throws Exception
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): never
    {
        throw new Exception('Resources may not be implicitly resolved from route bindings.');
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @throws Exception
     */
    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field = null): ?Model
    {
        throw new Exception('Resources may not be implicitly resolved from child route bindings.');
    }

    /**
     * Determine if the given attribute exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->resource[$offset]);
    }

    /**
     * Get the value for a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->resource[$offset];
    }

    /**
     * Set the value for a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->resource[$offset] = $value;
    }

    /**
     * Unset the value for a given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->resource[$offset]);
    }

    /**
     * Determine if an attribute exists on the resource.
     */
    public function __isset(string $key): bool
    {
        return isset($this->resource->{$key});
    }

    /**
     * Unset an attribute on the resource.
     */
    public function __unset(string $key): void
    {
        unset($this->resource->{$key});
    }

    /**
     * Dynamically get properties from the underlying resource.
     */
    public function __get(string $key): mixed
    {
        return $this->resource->{$key};
    }

    /**
     * Dynamically pass method calls to the underlying resource.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->forwardCallTo($this->resource, $method, $parameters);
    }
}
