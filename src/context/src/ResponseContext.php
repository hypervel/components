<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Swow\Psr7\Message\ResponsePlusInterface;

class ResponseContext
{
    public static function get(?int $coroutineId = null): ResponsePlusInterface
    {
        return Context::get(ResponseInterface::class, null, $coroutineId);
    }

    public static function set(ResponseInterface $response, ?int $coroutineId = null): ResponsePlusInterface
    {
        if (! $response instanceof ResponsePlusInterface) {
            throw new RuntimeException('The response must instanceof ResponsePlusInterface');
        }

        return Context::set(ResponseInterface::class, $response, $coroutineId);
    }

    public static function has(?int $coroutineId = null): bool
    {
        return Context::has(ResponseInterface::class, $coroutineId);
    }

    public static function getOrNull(?int $coroutineId = null): ?ResponsePlusInterface
    {
        return Context::get(ResponseInterface::class, null, $coroutineId);
    }
}
