<?php

declare(strict_types=1);

namespace Hypervel\Context;

use ArrayObject;
use Closure;
use Hypervel\Coroutine\Coroutine;

class ParentContext
{
    /**
     * Set a value in the parent coroutine's context.
     */
    public static function set(string $id, mixed $value): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::set($id, $value, Coroutine::parentId());
        }

        return Context::set($id, $value);
    }

    /**
     * Get a value from the parent coroutine's context.
     */
    public static function get(string $id, mixed $default = null): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::get($id, $default, Coroutine::parentId());
        }

        return Context::get($id, $default);
    }

    /**
     * Determine if a value exists in the parent coroutine's context.
     */
    public static function has(string $id): bool
    {
        if (Coroutine::inCoroutine()) {
            return Context::has($id, Coroutine::parentId());
        }

        return Context::has($id);
    }

    /**
     * Remove a value from the parent coroutine's context.
     */
    public static function destroy(string $id): void
    {
        if (Coroutine::inCoroutine()) {
            Context::destroy($id, Coroutine::parentId());
        } else {
            Context::destroy($id);
        }
    }

    /**
     * Override a value in the parent coroutine's context.
     */
    public static function override(string $id, Closure $closure): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::override($id, $closure, Coroutine::parentId());
        }

        return Context::override($id, $closure);
    }

    /**
     * Get or set a value in the parent coroutine's context.
     */
    public static function getOrSet(string $id, mixed $value): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::getOrSet($id, $value, Coroutine::parentId());
        }

        return Context::getOrSet($id, $value);
    }

    /**
     * Get the parent coroutine's context container.
     */
    public static function getContainer(): array|ArrayObject|null
    {
        if (Coroutine::inCoroutine()) {
            return Context::getContainer(Coroutine::parentId());
        }

        return Context::getContainer();
    }
}
