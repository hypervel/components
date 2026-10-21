<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Concerns;

use ReflectionClass;

trait ReadsAttributes
{
    /**
     * @var array<string, null|list<object>|object>
     */
    protected static array $attributeCache = [];

    /**
     * Resolve the nearest matching attribute in the class hierarchy.
     *
     * @template T of object
     *
     * @param class-string<T> $attributeClass
     * @return null|T
     */
    protected function resolveAttribute(string $attributeClass): mixed
    {
        $cacheKey = static::class . '@' . $attributeClass;

        if (array_key_exists($cacheKey, static::$attributeCache)) {
            return static::$attributeCache[$cacheKey]; // @phpstan-ignore return.type
        }

        $reflection = new ReflectionClass($this);

        do {
            $attributes = $reflection->getAttributes($attributeClass);

            if ($attributes !== []) {
                return static::$attributeCache[$cacheKey] = $attributes[0]->newInstance();
            }
        } while ($reflection = $reflection->getParentClass());

        return static::$attributeCache[$cacheKey] = null;
    }

    /**
     * Resolve all matching attributes in the class hierarchy.
     *
     * @template T of object
     *
     * @param class-string<T> $attributeClass
     * @return list<T>
     */
    protected function resolveAttributes(string $attributeClass): array
    {
        $cacheKey = static::class . '@' . $attributeClass . '[]';

        if (array_key_exists($cacheKey, static::$attributeCache)) {
            return static::$attributeCache[$cacheKey]; // @phpstan-ignore return.type
        }

        $instances = [];
        $reflection = new ReflectionClass($this);

        do {
            foreach ($reflection->getAttributes($attributeClass) as $attribute) {
                $instances[] = $attribute->newInstance();
            }
        } while ($reflection = $reflection->getParentClass());

        return static::$attributeCache[$cacheKey] = $instances; // @phpstan-ignore return.type
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$attributeCache = [];
    }
}
