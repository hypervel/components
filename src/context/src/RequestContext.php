<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swow\Psr7\Message\ServerRequestPlusInterface;

class RequestContext
{
    public static function get(?int $coroutineId = null): ServerRequestPlusInterface
    {
        return Context::get(ServerRequestInterface::class, null, $coroutineId);
    }

    public static function set(ServerRequestInterface $request, ?int $coroutineId = null): ServerRequestPlusInterface
    {
        if (! $request instanceof ServerRequestPlusInterface) {
            throw new RuntimeException('The request must instanceof ServerRequestPlusInterface');
        }

        return Context::set(ServerRequestInterface::class, $request, $coroutineId);
    }

    public static function has(?int $coroutineId = null): bool
    {
        return Context::has(ServerRequestInterface::class, $coroutineId);
    }

    public static function destroy(?int $coroutineId = null): void
    {
        Context::destroy(ServerRequestInterface::class, $coroutineId);
    }

    public static function getOrNull(?int $coroutineId = null): ?ServerRequestPlusInterface
    {
        return Context::get(ServerRequestInterface::class, null, $coroutineId);
    }
}
