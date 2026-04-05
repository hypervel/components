<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Exception;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Support\Str;

class ClientEvent
{
    /**
     * Handle a Pusher client event.
     */
    public static function handle(Connection $connection, array $event): void
    {
        if (! isset($event['event']) || ! is_string($event['event'])
            || ! isset($event['channel']) || ! is_string($event['channel'])
            || (isset($event['data']) && ! is_array($event['data']))
        ) {
            throw new Exception('Invalid client event data');
        }

        if (! Str::startsWith($event['event'], 'client-')) {
            return;
        }

        $acceptClientEventsFrom = $connection->app()->acceptClientEventsFrom();

        if (! in_array($acceptClientEventsFrom, ['all', 'members'])) {
            $connection->send(json_encode([
                'event' => 'pusher:error',
                'data' => json_encode([
                    'code' => 4301,
                    'message' => 'The app does not have client messaging enabled.',
                ]),
            ]));

            return;
        }

        $rebroadcastEvent = $event;

        if ($acceptClientEventsFrom === 'members') {
            $channel = app(ChannelManager::class)->for($connection->app())->find($event['channel']);

            $channelConnection = $channel?->find($connection);

            if (! $channelConnection) {
                $connection->send(json_encode([
                    'event' => 'pusher:error',
                    'data' => json_encode([
                        'code' => 4009,
                        'message' => 'The client is not a member of the specified channel.',
                    ]),
                ]));

                return;
            }

            // Regenerate event payload, ensuring we only include the expected fields and the authenticated user_id
            $rebroadcastEvent = [
                'event' => $event['event'],
                'channel' => $event['channel'],
                'data' => $event['data'] ?? null,
            ];

            if ($userId = $channelConnection->data('user_id')) {
                $rebroadcastEvent['user_id'] = $userId;
            }
        }

        static::whisper(
            $connection,
            $rebroadcastEvent
        );

        $webhookData = [
            'event' => $event['event'],
            'channel' => $event['channel'],
            'data' => $event['data'] ?? null,
        ];

        if (isset($rebroadcastEvent['user_id'])) {
            $webhookData['user_id'] = $rebroadcastEvent['user_id'];
        }

        app(WebhookDispatcher::class)->dispatch($connection->app(), 'client_event', $webhookData, $connection);
    }

    /**
     * Whisper a message to all connections on the channel associated with the event.
     */
    public static function whisper(Connection $connection, array $payload): void
    {
        EventDispatcher::dispatch(
            $connection->app(),
            $payload,
            $connection
        );
    }
}
