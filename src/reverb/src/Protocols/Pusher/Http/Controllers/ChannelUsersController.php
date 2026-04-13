<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;

class ChannelUsersController extends Controller
{
    use InteractsWithChannelInformation;

    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId, string $channel): JsonResponse
    {
        $context = $this->verify($request, $appId);

        $channelInstance = $context->channels->find($channel);

        if (! $channelInstance) {
            return new JsonResponse((object) [], 404);
        }

        if (! $this->isPresenceChannel($channelInstance)) {
            return new JsonResponse((object) [], 400);
        }

        $connections = app(MetricsHandler::class)
            ->gather($context->application, 'channel_users', ['channel' => $channelInstance->name()]);

        return new JsonResponse(['users' => $connections]);
    }
}
