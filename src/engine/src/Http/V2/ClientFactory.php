<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http\V2;

use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientInterface;

class ClientFactory implements ClientFactoryInterface
{
    /**
     * Create a new HTTP/2 client instance.
     */
    public function make(string $host, int $port = 80, bool $ssl = false): ClientInterface
    {
        return new Client($host, $port, $ssl);
    }
}
