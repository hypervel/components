<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Events;

use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;

class SentSaloonRequest
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public PendingRequest $pendingRequest,
        public Response $response,
    ) {
    }
}
