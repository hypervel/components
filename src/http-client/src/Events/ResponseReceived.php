<?php

declare(strict_types=1);

namespace Hypervel\HttpClient\Events;

use Hypervel\HttpClient\Request;
use Hypervel\HttpClient\Response;

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
