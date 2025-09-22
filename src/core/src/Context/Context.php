<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hyperf\Context\Context as HyperfContext;
use Hyperf\Engine\Coroutine;

class Context extends HyperfContext
{
    protected const DEPTH_KEY = 'di.depth';

    public function __call(string $method, array $arguments): mixed
    {
        return static::{$method}(...$arguments);
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
