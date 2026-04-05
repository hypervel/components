<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;

class ChannelsController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId): JsonResponse
    {
        $context = $this->verify($request, $appId);

        $channels = app(MetricsHandler::class)->gather($context->application, 'channels', [
            'filter' => $context->query['filter_by_prefix'] ?? null,
            'info' => $context->query['info'] ?? null,
        ]);

        return new JsonResponse(['channels' => array_map(fn ($item) => (object) $item, $channels)]);
    }
}
