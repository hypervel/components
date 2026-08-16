<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Events;

use Hypervel\Saloon\Http\PendingRequest;

class SendingSaloonRequest
{
    /**
     * Create a new event instance.
     */
    public function __construct(public PendingRequest $pendingRequest)
    {
    }
}
