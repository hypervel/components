<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;

class ConnectionsController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId): JsonResponse
    {
        $context = $this->verify($request, $appId);

        $connections = app(MetricsHandler::class)->gather($context->application, 'connections');

        return new JsonResponse(['connections' => count($connections)]);
    }
}
