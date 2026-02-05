<?php

declare(strict_types=1);

namespace Hypervel\Context;

use ArrayObject;
use Closure;
use Hypervel\Engine\Coroutine;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * TODO: Remove "extends \Hyperf\Context\Context" once all Hyperf dependencies are removed.
 * We temporarily extend the parent to share the static $nonCoContext storage with Hyperf
 * vendor code (e.g., Hyperf\HttpServer\Request) that still uses Hyperf\Context\Context.
 * Once porting is complete, remove the extends and uncomment $nonCoContext below.
 *
 * @template TKey of string
 * @template TValue
 */
class Context extends \Hyperf\Context\Context
{
    protected const DEPTH_KEY = 'di.depth';

    // TODO: Uncomment when removing "extends \Hyperf\Context\Context".
    // /** @var array<TKey, TValue> */
    // protected static array $nonCoContext = [];

    /**
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
     * Release the context when you are not in coroutine environment.
     *
     * @param TKey $id
     */
    public static function destroy(UnitEnum|string $id, ?int $coroutineId = null): void
    {
        $id = enum_value($id);

        if (Coroutine::id() > 0) {
            unset(Coroutine::getContextFor($coroutineId)[$id]);
        }

        unset(static::$nonCoContext[$id]);
    }

    /**
     * Copy the context from a coroutine to current coroutine.
     * This method will delete the origin values in current coroutine.
     */
    public static function copy(int $fromCoroutineId, array $keys = []): void
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

        $current->exchangeArray($map);
    }

    /**
     * Retrieve the value and override it by closure.
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

        $context->exchangeArray($map);
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
     * Unlike destroy() which clears from both contexts, this only affects
     * non-coroutine storage. Useful for clearing stale data before copying.
     */
    public static function clearFromNonCoroutine(array $keys): void
    {
        foreach ($keys as $key) {
            unset(static::$nonCoContext[$key]);
        }
    }

    /**
     * Destroy all context data for the specified coroutine, preserving only the depth key.
     */
    public static function destroyAll(?int $coroutineId = null): void
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
            static::destroy($key, $coroutineId);
        }
    }

    /**
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
