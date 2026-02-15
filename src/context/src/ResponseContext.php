<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hypervel\Contracts\Http\ResponsePlusInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class ResponseContext
{
    /**
     * Get the current response from context.
     */
    public static function get(?int $coroutineId = null): ResponsePlusInterface
    {
        return Context::get(ResponseInterface::class, null, $coroutineId);
    }

    /**
     * Set the current response in context.
     */
    public static function set(ResponseInterface $response, ?int $coroutineId = null): ResponsePlusInterface
    {
        if (! $response instanceof ResponsePlusInterface) {
            throw new RuntimeException('The response must instanceof ResponsePlusInterface');
        }

        return Context::set(ResponseInterface::class, $response, $coroutineId);
    }

    /**
     * Determine if a response exists in context.
     */
    public static function has(?int $coroutineId = null): bool
    {
        return Context::has(ResponseInterface::class, $coroutineId);
    }

    /**
     * Get the current response from context, or null if not set.
     */
    public static function getOrNull(?int $coroutineId = null): ?ResponsePlusInterface
    {
        return Context::get(ResponseInterface::class, null, $coroutineId);
    }
}
