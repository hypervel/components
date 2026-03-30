<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hypervel\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ResponseContext
{
    /**
     * Get the current response from context.
     */
    public static function get(?int $coroutineId = null): Response
    {
        return CoroutineContext::get(SymfonyResponse::class, null, $coroutineId);
    }

    /**
     * Set the current response in context.
     */
    public static function set(Response $response, ?int $coroutineId = null): Response
    {
        return CoroutineContext::set(SymfonyResponse::class, $response, $coroutineId);
    }

    /**
     * Determine if a response exists in context.
     */
    public static function has(?int $coroutineId = null): bool
    {
        return CoroutineContext::has(SymfonyResponse::class, $coroutineId);
    }

    /**
     * Remove the response from context.
     */
    public static function forget(?int $coroutineId = null): void
    {
        CoroutineContext::forget(SymfonyResponse::class, $coroutineId);
    }

    /**
     * Get the current response from context, or null if not set.
     */
    public static function getOrNull(?int $coroutineId = null): ?Response
    {
        return CoroutineContext::get(SymfonyResponse::class, null, $coroutineId);
    }
}
