<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Closure;
use Hypervel\Coroutine\Coroutine;

class ParentContext
{
    public static function set(string $id, mixed $value): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::set($id, $value, Coroutine::parentId());
        }

        return Context::set($id, $value);
    }

    public static function get(string $id, mixed $default = null): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::get($id, $default, Coroutine::parentId());
        }

        return Context::get($id, $default);
    }

    public static function has(string $id): bool
    {
        if (Coroutine::inCoroutine()) {
            return Context::has($id, Coroutine::parentId());
        }

        return Context::has($id);
    }

    public static function destroy(string $id): void
    {
        if (Coroutine::inCoroutine()) {
            Context::destroy($id, Coroutine::parentId());
        } else {
            Context::destroy($id);
        }
    }

    public static function override(string $id, Closure $closure): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::override($id, $closure, Coroutine::parentId());
        }

        return Context::override($id, $closure);
    }

    public static function getOrSet(string $id, mixed $value): mixed
    {
        if (Coroutine::inCoroutine()) {
            return Context::getOrSet($id, $value, Coroutine::parentId());
        }

        return Context::getOrSet($id, $value);
    }

    public static function getContainer()
    {
        if (Coroutine::inCoroutine()) {
            return Context::getContainer(Coroutine::parentId());
        }

        return Context::getContainer();
    }
}
