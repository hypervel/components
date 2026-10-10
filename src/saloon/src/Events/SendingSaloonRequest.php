<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Events;

use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Saloon\Http\PendingRequest;

class SendingSaloonRequest
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public PendingRequest $pendingRequest)
    {
    }
}
