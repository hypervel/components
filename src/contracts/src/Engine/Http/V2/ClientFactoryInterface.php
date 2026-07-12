<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface ClientFactoryInterface
{
    /**
     * Create an HTTP/2 client.
     */
    public function make(string $host, int $port = 80, bool $ssl = false): ClientInterface;
}
