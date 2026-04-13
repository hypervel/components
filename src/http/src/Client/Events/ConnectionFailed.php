<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Events;

use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Request;

class ConnectionFailed
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Request $request,
        public ConnectionException $exception
    ) {
    }
}
