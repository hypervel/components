<?php

declare(strict_types=1);

namespace Hypervel\Context;

use ArrayObject;
use Closure;
use Hypervel\Coroutine\Coroutine;

class ParentCoroutineContext
{
    /**
     * Set a value in the parent coroutine's context.
     */
    public static function set(string $id, mixed $value): mixed
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::set($id, $value, Coroutine::parentId());
        }

        return CoroutineContext::set($id, $value);
    }

    /**
     * Get a value from the parent coroutine's context.
     */
    public static function get(string $id, mixed $default = null): mixed
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::get($id, $default, Coroutine::parentId());
        }

        return CoroutineContext::get($id, $default);
    }

    /**
     * Determine if a value exists in the parent coroutine's context.
     */
    public static function has(string $id): bool
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::has($id, Coroutine::parentId());
        }

        return CoroutineContext::has($id);
    }

    /**
     * Remove a value from the parent coroutine's context.
     */
    public static function forget(string $id): void
    {
        if (Coroutine::inCoroutine()) {
            CoroutineContext::forget($id, Coroutine::parentId());
        } else {
            CoroutineContext::forget($id);
        }
    }

    /**
     * Override a value in the parent coroutine's context.
     */
    public static function override(string $id, Closure $closure): mixed
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::override($id, $closure, Coroutine::parentId());
        }

        return CoroutineContext::override($id, $closure);
    }

    /**
     * Get or set a value in the parent coroutine's context.
     */
    public static function getOrSet(string $id, mixed $value): mixed
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::getOrSet($id, $value, Coroutine::parentId());
        }

        return CoroutineContext::getOrSet($id, $value);
    }

    /**
     * Get the parent coroutine's context container.
     */
    public static function getContainer(): array|ArrayObject|null
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::getContainer(Coroutine::parentId());
        }

        return CoroutineContext::getContainer();
    }
}
