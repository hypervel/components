<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;

class EventsController extends Controller
{
    use InteractsWithChannelInformation;

    /**
     * Handle the request.
     */
    public function __invoke(Request $request, string $appId): JsonResponse
    {
        $context = $this->verify($request, $appId);

        $payload = json_decode($context->body, associative: true, flags: JSON_THROW_ON_ERROR);

        $validator = Validator::make($payload, [
            'name' => ['required', 'string'],
            'data' => ['required', 'string'],
            'channels' => ['required_without:channel', 'array'],
            'channel' => ['required_without:channels', 'string'],
            'socket_id' => ['string'],
            'info' => ['string'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse($validator->errors(), 422);
        }

        $channels = Arr::wrap($payload['channels'] ?? $payload['channel'] ?? []);
        if ($except = $payload['socket_id'] ?? null) {
            $except = $context->channels->connections()[$except] ?? null;
        }

        EventDispatcher::dispatch(
            $context->application,
            [
                'event' => $payload['name'],
                'channels' => $channels,
                'data' => $payload['data'],
            ],
            $except ? $except->connection() : null
        );

        if (isset($payload['info'])) {
            $channels = app(MetricsHandler::class)
                ->gather($context->application, 'channels', ['info' => $payload['info'], 'channels' => $channels]);

            return new JsonResponse(['channels' => array_map(fn ($channel) => (object) $channel, $channels)]);
        }

        return new JsonResponse((object) []);
    }
}
