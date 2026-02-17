<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

class PriorityMiddleware
{
    public const DEFAULT_PRIORITY = 0;

    public function __construct(public string $middleware, public int $priority = self::DEFAULT_PRIORITY)
    {
    }
}
