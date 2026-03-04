<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Events;

use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\Response;

class ResponseReceived
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Request $request,
        public Response $response
    ) {
    }
}
