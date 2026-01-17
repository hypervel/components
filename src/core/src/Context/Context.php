<?php

declare(strict_types=1);

namespace Hypervel\Context;

use BackedEnum;
use Closure;
use Hyperf\Context\Context as HyperfContext;
use Hyperf\Engine\Coroutine;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Context extends HyperfContext
{
    protected const DEPTH_KEY = 'di.depth';

    public function __call(string $method, array $arguments): mixed
    {
        return static::{$method}(...$arguments);
    }

    /**
     * Set a value in the context.
     */
    public static function set(BackedEnum|UnitEnum|string $id, mixed $value, ?int $coroutineId = null): mixed
    {
        return parent::set(enum_value($id), $value, $coroutineId);
    }

    /**
     * Get a value from the context.
     */
    public static function get(BackedEnum|UnitEnum|string $id, mixed $default = null, ?int $coroutineId = null): mixed
    {
        return parent::get(enum_value($id), $default, $coroutineId);
    }

    /**
     * Determine if a value exists in the context.
     */
    public static function has(BackedEnum|UnitEnum|string $id, ?int $coroutineId = null): bool
    {
        return parent::has(enum_value($id), $coroutineId);
    }

    /**
     * Remove a value from the context.
     */
    public static function destroy(BackedEnum|UnitEnum|string $id, ?int $coroutineId = null): void
    {
        parent::destroy(enum_value($id), $coroutineId);
    }

    /**
     * Retrieve the value and override it by closure.
     */
    public static function override(BackedEnum|UnitEnum|string $id, Closure $closure, ?int $coroutineId = null): mixed
    {
        return parent::override(enum_value($id), $closure, $coroutineId);
    }

    /**
     * Retrieve the value and store it if not exists.
     */
    public static function getOrSet(BackedEnum|UnitEnum|string $id, mixed $value, ?int $coroutineId = null): mixed
    {
        return parent::getOrSet(enum_value($id), $value, $coroutineId);
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
}
