<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use ReflectionAttribute;

class DataAttributesCollection
{
    /**
     * Create a new attribute recipe collection.
     *
     * @param array<class-string, non-empty-list<ReflectionAttribute<object>>> $attributes
     */
    public function __construct(
        protected readonly array $attributes = [],
    ) {
    }

    /**
     * Determine if an attribute recipe is present.
     *
     * @param class-string $type
     */
    public function has(string $type): bool
    {
        return array_key_exists($type, $this->attributes);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     * @return null|ReflectionAttribute<T>
     */
    public function first(string $type): ?ReflectionAttribute
    {
        return $this->attributes[$type][0] ?? null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     * @return list<ReflectionAttribute<T>>
     */
    public function all(string $type): array
    {
        return $this->attributes[$type] ?? [];
    }
}
