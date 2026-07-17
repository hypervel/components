<?php

declare(strict_types=1);

namespace Hypervel\Context;

use ArrayObject;
use Closure;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineDestroyedException;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @template TKey of string
 * @template TValue
 */
class CoroutineContext
{
    /** @var array<TKey, TValue> */
    protected static array $nonCoroutineContext = [];

    /**
     * Store a value in the current context.
     *
     * @param TKey $id
     * @param TValue $value
     * @return TValue
     *
     * @throws CoroutineDestroyedException
     */
    public static function set(UnitEnum|string $id, mixed $value, ?int $coroutineId = null): mixed
    {
        $id = enum_value($id);
        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            $context[$id] = $value;
        } elseif ($coroutineId === null) {
            static::$nonCoroutineContext[$id] = $value;
        } else {
            throw new CoroutineDestroyedException(sprintf('Coroutine #%d has been destroyed.', $coroutineId));
        }

        return $value;
    }

    /**
     * Retrieve a value from the current context.
     *
     * @param TKey $id
     * @return TValue
     */
    public static function get(UnitEnum|string $id, mixed $default = null, ?int $coroutineId = null): mixed
    {
        $id = enum_value($id);
        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            return $context[$id] ?? $default;
        }

        return $coroutineId === null
            ? static::$nonCoroutineContext[$id] ?? $default
            : $default;
    }

    /**
     * Determine if a value exists in the current context.
     *
     * @param TKey $id
     */
    public static function has(UnitEnum|string $id, ?int $coroutineId = null): bool
    {
        $id = enum_value($id);
        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            return isset($context[$id]);
        }

        return $coroutineId === null && isset(static::$nonCoroutineContext[$id]);
    }

    /**
     * Remove a value from the current context.
     *
     * @param TKey $id
     */
    public static function forget(UnitEnum|string $id, ?int $coroutineId = null): void
    {
        $id = enum_value($id);
        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            unset($context[$id]);
        } elseif ($coroutineId === null) {
            unset(static::$nonCoroutineContext[$id]);
        }
    }

    /**
     * Copy context from another coroutine into the current coroutine.
     *
     * Merges into the current coroutine's context — existing values that are
     * not in the source are preserved. Matching keys are overwritten.
     */
    public static function copyFrom(int $fromCoroutineId, array $keys = []): void
    {
        static::setMany(
            static::captureFrom($keys, $fromCoroutineId),
            Coroutine::id(),
        );
    }

    /**
     * Capture context values as an array.
     *
     * Replicable values are copied in the calling coroutine at capture time.
     *
     * @return array<TKey, TValue>
     */
    public static function captureFrom(array $keys = [], ?int $fromCoroutineId = null): array
    {
        $from = Coroutine::getContextFor($fromCoroutineId);

        if ($from === null) {
            return [];
        }

        $map = $keys
            ? array_intersect_key($from->getArrayCopy(), array_flip($keys))
            : $from->getArrayCopy();

        foreach ($map as $key => $value) {
            if ($value instanceof ReplicableContext) {
                $map[$key] = $value->replicate();
            }
        }

        return $map;
    }

    /**
     * Retrieve a value and replace it with the result of a closure.
     *
     * @param TKey $id
     * @param (Closure(TValue):TValue) $closure
     */
    public static function override(UnitEnum|string $id, Closure $closure, ?int $coroutineId = null): mixed
    {
        $value = null;

        if (self::has($id, $coroutineId)) {
            $value = self::get($id, null, $coroutineId);
        }

        $value = $closure($value);

        self::set($id, $value, $coroutineId);

        return $value;
    }

    /**
     * Retrieve the value and store it if not exists.
     *
     * @param TKey $id
     * @param TValue $value
     * @return TValue
     */
    public static function getOrSet(UnitEnum|string $id, mixed $value, ?int $coroutineId = null): mixed
    {
        if (! self::has($id, $coroutineId)) {
            return self::set($id, value($value), $coroutineId);
        }

        return self::get($id, null, $coroutineId);
    }

    /**
     * Set multiple key-value pairs in the context.
     */
    public static function setMany(array $values, ?int $coroutineId = null): void
    {
        if ($values === []) {
            return;
        }

        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            foreach ($values as $key => $value) {
                $context[(string) $key] = $value;
            }

            return;
        }

        if ($coroutineId !== null) {
            throw new CoroutineDestroyedException(sprintf('Coroutine #%d has been destroyed.', $coroutineId));
        }

        foreach ($values as $key => $value) {
            static::$nonCoroutineContext[(string) $key] = $value;
        }
    }

    /**
     * Copy context data from non-coroutine context to the specified coroutine context.
     *
     * Merges into the target coroutine's context — existing values that are
     * not in the source are preserved. Matching keys are overwritten.
     */
    public static function copyFromNonCoroutine(array $keys = [], ?int $coroutineId = null): void
    {
        if (is_null($context = Coroutine::getContextFor($coroutineId))) {
            return;
        }

        if ($keys) {
            $map = array_intersect_key(static::$nonCoroutineContext, array_flip($keys));
        } else {
            $map = static::$nonCoroutineContext;
        }

        foreach ($map as $key => $value) {
            if ($value instanceof ReplicableContext) {
                $value = $value->replicate();
            }
            $context[$key] = $value;
        }
    }

    /**
     * Copy context data from the specified coroutine context to non-coroutine context.
     *
     * Tests only. This copies coroutine-local values into worker-global storage,
     * so request-time use can leak or race across concurrent requests.
     */
    public static function copyToNonCoroutine(array $keys = [], ?int $coroutineId = null): void
    {
        if (is_null($context = Coroutine::getContextFor($coroutineId))) {
            return;
        }

        if ($keys) {
            foreach ($keys as $key) {
                if (isset($context[$key])) {
                    static::$nonCoroutineContext[$key] = $context[$key];
                }
            }
        } else {
            foreach ($context as $key => $value) {
                static::$nonCoroutineContext[$key] = $value;
            }
        }
    }

    /**
     * Get a value from non-coroutine context only.
     *
     * Unlike get() which reads from coroutine context when inside a coroutine,
     * this always reads from non-coroutine storage regardless of current context.
     *
     * @param TKey $id
     * @param TValue $default
     * @return TValue
     */
    public static function getFromNonCoroutine(UnitEnum|string $id, mixed $default = null): mixed
    {
        $id = enum_value($id);

        return static::$nonCoroutineContext[$id] ?? $default;
    }

    /**
     * Clear specific keys from non-coroutine context only.
     *
     * Tests only. This mutates worker-global storage, so request-time use can
     * race with concurrent requests using the same keys.
     */
    public static function clearFromNonCoroutine(array $keys): void
    {
        foreach ($keys as $key) {
            unset(static::$nonCoroutineContext[$key]);
        }
    }

    /**
     * Flush all context data for the specified coroutine.
     */
    public static function flush(?int $coroutineId = null): void
    {
        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            $context->exchangeArray([]);
        } elseif ($coroutineId === null) {
            static::$nonCoroutineContext = [];
        }
    }

    /**
     * Get the raw context storage for the current or specified coroutine.
     *
     * @return null|array<TKey, TValue>|ArrayObject<TKey, TValue>
     */
    public static function getContainer(?int $coroutineId = null): array|ArrayObject|null
    {
        $context = Coroutine::getContextFor($coroutineId);

        if ($context !== null) {
            return $context;
        }

        return $coroutineId === null ? static::$nonCoroutineContext : null;
    }
}
