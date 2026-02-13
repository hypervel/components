<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Swoole\Http\Response;

class FdGetter
{
    /**
     * Get the file descriptor from a response.
     */
    public function get(Response $response): int
    {
        return $response->fd;
    }
}
