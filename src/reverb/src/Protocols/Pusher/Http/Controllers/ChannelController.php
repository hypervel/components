<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;

class ChannelController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId, string $channel): JsonResponse
    {
        $context = $this->verify($request, $appId);

        $channel = app(MetricsHandler::class)->gather($context->application, 'channel', [
            'channel' => $channel,
            'info' => isset($context->query['info']) ? $context->query['info'] . ',occupied' : 'occupied',
        ]);

        return new JsonResponse((object) $channel);
    }
}
