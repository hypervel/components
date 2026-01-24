<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayAccess;
use ArrayIterator;
use Hyperf\Conditionable\Conditionable;
use Hyperf\Macroable\Macroable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\InteractsWithData;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @implements \Hypervel\Contracts\Support\Arrayable<TKey, TValue>
 * @implements \ArrayAccess<TKey, TValue>
 */
class Fluent implements Arrayable, ArrayAccess, IteratorAggregate, Jsonable, JsonSerializable
{
    use Conditionable, InteractsWithData, Macroable {
        __call as macroCall;
    }

    /**
     * All of the attributes set on the fluent instance.
     *
     * @var array<TKey, TValue>
     */
    protected array $attributes = [];

    /**
     * Create a new fluent instance.
     *
     * @param  iterable<TKey, TValue>  $attributes
     */
    public function __construct(iterable $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Create a new fluent instance.
     *
     * @param  iterable<TKey, TValue>  $attributes
     */
    public static function make(iterable $attributes = []): static
    {
        return new static($attributes);
    }

    /**
     * Get an attribute from the fluent instance using "dot" notation.
     *
     * @template TGetDefault
     *
     * @param  TGetDefault|(\Closure(): TGetDefault)  $default
     * @return TValue|TGetDefault
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->attributes, $key, $default);
    }

    /**
     * Set an attribute on the fluent instance using "dot" notation.
     */
    public function set(string $key, mixed $value): static
    {
        data_set($this->attributes, $key, $value);

        return $this;
    }

    /**
     * Fill the fluent instance with an array of attributes.
     *
     * @param  iterable<TKey, TValue>  $attributes
     */
    public function fill(iterable $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * Get an attribute from the fluent instance.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        return value($default);
    }

    /**
     * Get the value of the given key as a new Fluent instance.
     */
    public function scope(string $key, mixed $default = null): static
    {
        return new static(
            (array) $this->get($key, $default)
        );
    }

    /**
     * Get all of the attributes from the fluent instance.
     */
    public function all(mixed $keys = null): array
    {
        $data = $this->data();

        if (! $keys) {
            return $data;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($data, $key));
        }

        return $results;
    }

    /**
     * Get data from the fluent instance.
     */
    protected function data(?string $key = null, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    /**
     * Get the attributes from the fluent instance.
     *
     * @return array<TKey, TValue>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the fluent instance to an array.
     *
     * @return array<TKey, TValue>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array<TKey, TValue>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the fluent instance to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the fluent instance to pretty print formatted JSON.
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }

    /**
     * Determine if the fluent instance is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->attributes);
    }

    /**
     * Determine if the fluent instance is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * Determine if the given offset exists.
     *
     * @param  TKey  $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Get the value for a given offset.
     *
     * @param  TKey  $offset
     * @return TValue|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->value($offset);
    }

    /**
     * Set the value at the given offset.
     *
     * @param  TKey  $offset
     * @param  TValue  $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Unset the value at the given offset.
     *
     * @param  TKey  $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Get an iterator for the attributes.
     *
     * @return ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    /**
     * Handle dynamic calls to the fluent instance to set attributes.
     *
     * @param  array<int, TValue>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        $this->attributes[$method] = count($parameters) > 0 ? array_first($parameters) : true;

        return $this;
    }

    /**
     * Dynamically retrieve the value of an attribute.
     *
     * @return TValue|null
     */
    public function __get(string $key): mixed
    {
        return $this->value($key);
    }

    /**
     * Dynamically set the value of an attribute.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->offsetSet($key, $value);
    }

    /**
     * Dynamically check if an attribute is set.
     */
    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Dynamically unset an attribute.
     */
    public function __unset(string $key): void
    {
        $this->offsetUnset($key);
    }
}
