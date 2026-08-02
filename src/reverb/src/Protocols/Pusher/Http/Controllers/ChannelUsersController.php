<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\Protocols\Pusher\MetricType;

class ChannelUsersController extends Controller
{
    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId, string $channel): JsonResponse
    {
        $context = $this->verify($request, $appId);

        $presence = app(MetricsHandler::class)->gather(
            $context->application,
            MetricType::Presence->value,
            ['channel' => $channel],
        );

        if (! $presence['exists']) {
            return new JsonResponse((object) [], 404);
        }

        if (! $presence['presence']) {
            return new JsonResponse((object) [], 400);
        }

        $users = collect($presence['users'])
            ->map(fn (array $user): array => ['id' => $user['user_id']])
            ->values()
            ->all();

        return new JsonResponse(['users' => $users]);
    }
}
