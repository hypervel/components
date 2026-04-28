<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Reverb\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Support\Facades\Validator;

class EventsBatchController extends Controller
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
            'batch' => ['required', 'array'],
            'batch.*.name' => ['required', 'string'],
            'batch.*.data' => ['required', 'string'],
            'batch.*.channel' => ['required_without:channels', 'string'],
            'batch.*.socket_id' => ['string'],
            'batch.*.info' => ['string'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse($validator->errors(), 422);
        }

        $items = collect($payload['batch'])->map(function (array $item) use ($context) {
            EventDispatcher::dispatch(
                $context->application,
                [
                    'event' => $item['name'],
                    'channel' => $item['channel'],
                    'data' => $item['data'],
                ],
                isset($item['socket_id']) ? ($context->channels->connections()[$item['socket_id']] ?? null)?->connection() : null
            );

            if (isset($item['info'])) {
                return app(MetricsHandler::class)->gather(
                    $context->application,
                    'channel',
                    ['channel' => $item['channel'], 'info' => $item['info']]
                );
            }

            return [];
        });

        if ($items->contains(fn ($item) => ! empty($item))) {
            return new JsonResponse(['batch' => array_map(fn ($item) => (object) $item, $items->all())]);
        }

        return new JsonResponse(['batch' => (object) []]);
    }
}
