<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hypervel\Http\Request;
use UnitEnum;

use function Hypervel\Support\enum_value;

class RequestContext
{
    protected const string VALUES_CONTEXT_KEY = '__context.request_values';

    /**
     * Get the current request from context.
     */
    public static function get(?int $coroutineId = null): Request
    {
        return CoroutineContext::get(Request::class, null, $coroutineId);
    }

    /**
     * Set the current request in context.
     *
     * Replacing the request clears the coroutine's internal request-scoped caches.
     */
    public static function set(Request $request, ?int $coroutineId = null): Request
    {
        if (static::getOrNull($coroutineId) !== $request) {
            CoroutineContext::forget(self::VALUES_CONTEXT_KEY, $coroutineId);
        }

        return CoroutineContext::set(Request::class, $request, $coroutineId);
    }

    /**
     * Determine if a request exists in context.
     */
    public static function has(?int $coroutineId = null): bool
    {
        return CoroutineContext::has(Request::class, $coroutineId);
    }

    /**
     * Remove the request from context.
     *
     * Also clear the coroutine's internal request-scoped caches.
     */
    public static function forget(?int $coroutineId = null): void
    {
        CoroutineContext::forget(Request::class, $coroutineId);
        CoroutineContext::forget(self::VALUES_CONTEXT_KEY, $coroutineId);
    }

    /**
     * Get the current request from context, or null if not set.
     */
    public static function getOrNull(?int $coroutineId = null): ?Request
    {
        return CoroutineContext::get(Request::class, null, $coroutineId);
    }

    /**
     * Retrieve a value scoped to the current request.
     *
     * @internal framework cache storage; use request attributes for application request metadata
     */
    public static function getValue(UnitEnum|string $key, mixed $default = null, ?int $coroutineId = null): mixed
    {
        $key = is_string($key) ? $key : enum_value($key);

        return CoroutineContext::get(self::VALUES_CONTEXT_KEY, null, $coroutineId)?->values[$key] ?? $default;
    }

    /**
     * Store a value until the request is replaced or forgotten.
     *
     * @internal framework cache storage; use request attributes for application request metadata
     */
    public static function setValue(UnitEnum|string $key, mixed $value, ?int $coroutineId = null): mixed
    {
        $key = is_string($key) ? $key : enum_value($key);
        $state = CoroutineContext::get(self::VALUES_CONTEXT_KEY, null, $coroutineId)
            ?? CoroutineContext::set(self::VALUES_CONTEXT_KEY, new RequestContextState, $coroutineId);
        $state->values[$key] = $value;

        return $value;
    }

    /**
     * Remove a value scoped to the current request.
     *
     * @internal framework cache storage; use request attributes for application request metadata
     */
    public static function forgetValue(UnitEnum|string $key, ?int $coroutineId = null): void
    {
        $key = is_string($key) ? $key : enum_value($key);
        $state = CoroutineContext::get(self::VALUES_CONTEXT_KEY, null, $coroutineId);

        if ($state !== null) {
            unset($state->values[$key]);
        }
    }

    /**
     * Retrieve a request-scoped value, storing the supplied value when missing.
     *
     * @internal framework cache storage; use request attributes for application request metadata
     */
    public static function getOrSetValue(UnitEnum|string $key, mixed $value, ?int $coroutineId = null): mixed
    {
        return static::getValue($key, coroutineId: $coroutineId)
            ?? static::setValue($key, value($value), $coroutineId);
    }
}
