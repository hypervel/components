<?php

declare(strict_types=1);

namespace Hypervel\Support;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @mixin \Hypervel\Support\Enumerable<TKey, TValue>
 * @mixin TValue
 */
class HigherOrderCollectionProxy
{
    /**
     * Create a new proxy instance.
     *
     * @param \Hypervel\Support\Enumerable<TKey, TValue> $collection
     */
    public function __construct(
        protected Enumerable $collection,
        protected string $method
    ) {}

    /**
     * Proxy accessing an attribute onto the collection items.
     */
    public function __get(string $key): mixed
    {
        return $this->collection->{$this->method}(function ($value) use ($key) {
            return is_array($value) ? $value[$key] : $value->{$key};
        });
    }

    /**
     * Proxy a method call onto the collection items.
     *
     * @param array<mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->collection->{$this->method}(function ($value) use ($method, $parameters) {
            return is_string($value)
                ? $value::{$method}(...$parameters)
                : $value->{$method}(...$parameters);
        });
    }
}
