<?php

declare(strict_types=1);

namespace Hypervel\HttpClient\Events;

use Hypervel\HttpClient\Request;

class RequestSending
{
    /**
     * Create a new event instance.
     */
    public function __construct(public Request $request)
    {
    }
}
