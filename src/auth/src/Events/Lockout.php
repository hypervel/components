<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Http\Request;

class Lockout
{
    /**
     * Create a new event instance.
     *
     * @param Request $request the throttled request
     */
    public function __construct(
        public Request $request,
    ) {
    }
}
