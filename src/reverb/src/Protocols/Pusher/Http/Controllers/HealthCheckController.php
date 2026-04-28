<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;

class HealthCheckController
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['health' => 'OK']);
    }
}
