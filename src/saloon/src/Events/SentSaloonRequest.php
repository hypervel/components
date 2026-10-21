<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Events;

use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;

class SentSaloonRequest
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public PendingRequest $pendingRequest,
        public Response $response,
    ) {
    }
}
