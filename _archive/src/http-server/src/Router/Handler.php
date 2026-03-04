<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Router;

class Handler
{
    /**
     * @param array|callable|string $callback
     */
    public function __construct(public mixed $callback, public string $route, public array $options = [])
    {
    }
}
