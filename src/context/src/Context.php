<?php

declare(strict_types=1);

namespace Hypervel\Context;

use ArrayObject;
use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Engine\Coroutine;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @template TKey of string
 * @template TValue
 */
class Context
{
    protected const DEPTH_KEY = 'di.depth';

    protected const PROPAGATED_CONTEXT_KEY = '__context.propagated';

    /** @var array<TKey, TValue> */
    protected static array $nonCoContext = [];

    /**
     * Store a value in the current context.
     *
     * @param TKey $id
     * @param TValue $value
     * @return TValue
     */
    public static function set(UnitEnum|string $id, mixed $value, ?int $coroutineId = null): mixed
    {
        $id = enum_value($id);

        if (Coroutine::id() > 0) {
            Coroutine::getContextFor($coroutineId)[$id] = $value;
        } else {
            static::$nonCoContext[$id] = $value;
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

        if (Coroutine::id() > 0) {
            return Coroutine::getContextFor($coroutineId)[$id] ?? $default;
        }

        return static::$nonCoContext[$id] ?? $default;
    }

    /**
     * Determine if a value exists in the current context.
     *
     * @param TKey $id
     */
    public static function has(UnitEnum|string $id, ?int $coroutineId = null): bool
    {
        $id = enum_value($id);

        if (Coroutine::id() > 0) {
            return isset(Coroutine::getContextFor($coroutineId)[$id]);
        }

        return isset(static::$nonCoContext[$id]);
    }

    /**
     * Get the propagated context instance for the current coroutine.
     *
     * Propagated context stores metadata that automatically flows into log entries
     * and queued job payloads. Unlike raw context (set/get), propagated values are
     * serialized when dispatching jobs and deserialized when the job runs.
     *
     * This creates the PropagatedContext instance on first access. For hot paths
     * that should avoid unnecessary allocation (log processors, queue hooks), use
     * hasPropagated() to check first.
     */
    public static function propagated(): PropagatedContext
    {
        return self::getOrSet(
            self::PROPAGATED_CONTEXT_KEY,
            fn () => new PropagatedContext(app(Dispatcher::class))
        );
    }

    /**
     * Determine if a PropagatedContext instance exists for the current coroutine.
     *
     * Unlike propagated(), this does NOT create one if it doesn't exist. Use this
     * in hot paths (log processors, queue payload hooks) to avoid allocating an
     * empty PropagatedContext on every request when the app never uses propagated
     * context.
     */
    public static function hasPropagated(): bool
    {
        return self::has(self::PROPAGATED_CONTEXT_KEY);
    }

    /**
     * Remove a value from the current context.
     *
     * @param TKey $id
     */
    public static function forget(UnitEnum|string $id, ?int $coroutineId = null): void
    {
        $id = enum_value($id);

        if (Coroutine::id() > 0) {
            unset(Coroutine::getContextFor($coroutineId)[$id]);
        }

        unset(static::$nonCoContext[$id]);
    }

    /**
     * Copy context from another coroutine into the current coroutine.
     *
     * Merges into the current coroutine's context — existing values that are
     * not in the source are preserved. Matching keys are overwritten.
     */
    public static function copyFrom(int $fromCoroutineId, array $keys = []): void
    {
        $from = Coroutine::getContextFor($fromCoroutineId);

        if ($from === null) {
            return;
        }

        $current = Coroutine::getContextFor();

        if ($keys) {
            $map = array_intersect_key($from->getArrayCopy(), array_flip($keys));
        } else {
            $map = $from->getArrayCopy();
        }

        // Replicate the PropagatedContext so the child gets its own instance
        // instead of sharing the parent's object reference.
        if (isset($map[self::PROPAGATED_CONTEXT_KEY])
            && $map[self::PROPAGATED_CONTEXT_KEY] instanceof PropagatedContext
        ) {
            $map[self::PROPAGATED_CONTEXT_KEY] = $map[self::PROPAGATED_CONTEXT_KEY]->replicate();
        }

        foreach ($map as $key => $value) {
            $current[$key] = $value;
        }
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
        foreach ($values as $key => $value) {
            static::set($key, $value, $coroutineId);
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
            $map = array_intersect_key(static::$nonCoContext, array_flip($keys));
        } else {
            $map = static::$nonCoContext;
        }

        // Replicate the PropagatedContext so the target coroutine gets its own
        // instance instead of sharing the non-coroutine context's object reference.
        if (isset($map[self::PROPAGATED_CONTEXT_KEY])
            && $map[self::PROPAGATED_CONTEXT_KEY] instanceof PropagatedContext
        ) {
            $map[self::PROPAGATED_CONTEXT_KEY] = $map[self::PROPAGATED_CONTEXT_KEY]->replicate();
        }

        foreach ($map as $key => $value) {
            $context[$key] = $value;
        }
    }

    /**
     * Copy context data from the specified coroutine context to non-coroutine context.
     */
    public static function copyToNonCoroutine(array $keys = [], ?int $coroutineId = null): void
    {
        if (is_null($context = Coroutine::getContextFor($coroutineId))) {
            return;
        }

        if ($keys) {
            foreach ($keys as $key) {
                if (isset($context[$key])) {
                    static::$nonCoContext[$key] = $context[$key];
                }
            }
        } else {
            foreach ($context as $key => $value) {
                static::$nonCoContext[$key] = $value;
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

        return static::$nonCoContext[$id] ?? $default;
    }

    /**
     * Clear specific keys from non-coroutine context only.
     *
     * Unlike forget() which clears from both contexts, this only affects
     * non-coroutine storage. Useful for clearing stale data before copying.
     */
    public static function clearFromNonCoroutine(array $keys): void
    {
        foreach ($keys as $key) {
            unset(static::$nonCoContext[$key]);
        }
    }

    /**
     * Flush all context data for the specified coroutine, preserving only the depth key.
     */
    public static function flush(?int $coroutineId = null): void
    {
        $coroutineId = $coroutineId ?: Coroutine::id();

        // Clear non-coroutine context in non-coroutine environment.
        if ($coroutineId < 0) {
            static::$nonCoContext = [];
            return;
        }

        if (! $context = Coroutine::getContextFor($coroutineId)) {
            return;
        }

        $contextKeys = [];
        foreach ($context as $key => $_) {
            if ($key === static::DEPTH_KEY) {
                continue;
            }
            $contextKeys[] = $key;
        }

        foreach ($contextKeys as $key) {
            static::forget($key, $coroutineId);
        }
    }

    /**
     * Get the raw context storage for the current or specified coroutine.
     *
     * @return null|array<TKey, TValue>|ArrayObject<TKey, TValue>
     */
    public static function getContainer(?int $coroutineId = null): array|ArrayObject|null
    {
        if (Coroutine::id() > 0) {
            return Coroutine::getContextFor($coroutineId);
        }

        return static::$nonCoContext;
    }
}
