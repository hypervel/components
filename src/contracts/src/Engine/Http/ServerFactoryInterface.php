<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface ServerFactoryInterface
{
    /**
     * Create an HTTP server.
     */
    public function make(string $name, int $port = 0): ServerInterface;
}
