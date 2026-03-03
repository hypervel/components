<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Events;

use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestHandled
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
    ) {
    }
}
