<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Context\Context;
use Hypervel\Support\Arr;
use Hypervel\Support\Onceable;
use WeakMap;

trait PreventsCircularRecursion
{
    /**
     * The context key for the recursion cache.
     */
    protected const RECURSION_CACHE_CONTEXT_KEY = '__database.model.recursion_cache';

    /**
     * Prevent a method from being called multiple times on the same object within the same call stack.
     */
    protected function withoutRecursion(callable $callback, mixed $default = null): mixed
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $onceable = Onceable::tryFromTrace($trace, $callback);

        if (is_null($onceable)) {
            return call_user_func($callback);
        }

        $stack = static::getRecursiveCallStack($this);

        if (array_key_exists($onceable->hash, $stack)) {
            return is_callable($stack[$onceable->hash])
                ? static::setRecursiveCallValue($this, $onceable->hash, call_user_func($stack[$onceable->hash]))
                : $stack[$onceable->hash];
        }

        try {
            static::setRecursiveCallValue($this, $onceable->hash, $default);

            return call_user_func($onceable->callable);
        } finally {
            static::clearRecursiveCallValue($this, $onceable->hash);
        }
    }

    /**
     * Remove an entry from the recursion cache for an object.
     */
    protected static function clearRecursiveCallValue(object $object, string $hash): void
    {
        if ($stack = Arr::except(static::getRecursiveCallStack($object), $hash)) {
            static::getRecursionCache()->offsetSet($object, $stack);
        } elseif (static::getRecursionCache()->offsetExists($object)) {
            static::getRecursionCache()->offsetUnset($object);
        }
    }

    /**
     * Get the stack of methods being called recursively for the current object.
     *
     * @return array<string, mixed>
     */
    protected static function getRecursiveCallStack(object $object): array
    {
        return static::getRecursionCache()->offsetExists($object)
            ? static::getRecursionCache()->offsetGet($object)
            : [];
    }

    /**
     * Get the current recursion cache being used by the model.
     *
     * @return WeakMap<object, array<string, mixed>>
     */
    protected static function getRecursionCache(): WeakMap
    {
        return Context::getOrSet(self::RECURSION_CACHE_CONTEXT_KEY, fn () => new WeakMap());
    }

    /**
     * Set a value in the recursion cache for the given object and method.
     */
    protected static function setRecursiveCallValue(object $object, string $hash, mixed $value): mixed
    {
        static::getRecursionCache()->offsetSet(
            $object,
            tap(static::getRecursiveCallStack($object), fn (&$stack) => $stack[$hash] = $value),
        );

        return static::getRecursiveCallStack($object)[$hash];
    }
}
