<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hypervel\Http\Request;

class RequestContext
{
    /**
     * Get the current request from context.
     */
    public static function get(?int $coroutineId = null): Request
    {
        return CoroutineContext::get(Request::class, null, $coroutineId);
    }

    /**
     * Set the current request in context.
     */
    public static function set(Request $request, ?int $coroutineId = null): Request
    {
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
     */
    public static function forget(?int $coroutineId = null): void
    {
        CoroutineContext::forget(Request::class, $coroutineId);
    }

    /**
     * Get the current request from context, or null if not set.
     */
    public static function getOrNull(?int $coroutineId = null): ?Request
    {
        return CoroutineContext::get(Request::class, null, $coroutineId);
    }
}
