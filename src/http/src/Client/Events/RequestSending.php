<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Events;

use Hypervel\Http\Client\Request;

class RequestSending
{
    /**
     * Create a new event instance.
     */
    public function __construct(public Request $request)
    {
    }
}
