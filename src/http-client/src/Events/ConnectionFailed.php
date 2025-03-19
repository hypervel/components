<?php

declare(strict_types=1);

namespace Hypervel\HttpClient\Events;

use Hypervel\HttpClient\ConnectionException;
use Hypervel\HttpClient\Request;

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
