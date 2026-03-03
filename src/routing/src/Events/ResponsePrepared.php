<?php

declare(strict_types=1);

namespace Hypervel\Routing\Events;

use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResponsePrepared
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
