<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels\Concerns;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;

trait InteractsWithPresenceChannels
{
    use InteractsWithPrivateChannels;

    /**
     * Subscribe to the given channel.
     */
    public function subscribe(Connection $connection, ?string $auth = null, ?string $data = null, ?string $userId = null): void
    {
        $this->verify($connection, $auth, $data);

        $userData = $data ? json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR) : [];
        $presenceUserId = (string) ($userData['user_id'] ?? '');

        parent::subscribe($connection, $auth, $data, $presenceUserId ?: null);

        $result = $this->lastSubscriptionResult();

        if ($result->memberAdded || $presenceUserId === '') {
            EventDispatcher::dispatchInternalToChannel(
                $connection->app(),
                $this,
                [
                    'event' => 'pusher_internal:member_added',
                    'data' => json_encode((object) $userData),
                    'channel' => $this->name(),
                ],
                $connection
            );

            app(WebhookDispatcher::class)->dispatch($connection->app(), 'member_added', [
                'channel' => $this->name(),
                'user_id' => $presenceUserId,
            ]);
        }
    }

    /**
     * Unsubscribe from the given channel.
     */
    public function unsubscribe(Connection $connection, ?string $userId = null): void
    {
        $subscription = $this->connections->find($connection);
        $presenceUserId = $subscription?->data('user_id');

        parent::unsubscribe($connection, $presenceUserId ? (string) $presenceUserId : null);

        $result = $this->lastSubscriptionResult();

        if (! $subscription || ! $presenceUserId) {
            return;
        }

        if ($result->memberRemoved) {
            EventDispatcher::dispatchInternalToChannel(
                $connection->app(),
                $this,
                [
                    'event' => 'pusher_internal:member_removed',
                    'data' => json_encode(['user_id' => $presenceUserId]),
                    'channel' => $this->name(),
                ],
                $connection
            );

            app(WebhookDispatcher::class)->dispatch($connection->app(), 'member_removed', [
                'channel' => $this->name(),
                'user_id' => $presenceUserId,
            ]);
        }
    }

    /**
     * Get the data associated with the channel.
     */
    public function data(): array
    {
        $connections = collect($this->connections->all())
            ->map(fn ($connection) => $connection->data())
            ->unique('user_id');

        if ($connections->contains(fn ($connection) => ! isset($connection['user_id']))) {
            return [
                'presence' => [
                    'count' => 0,
                    'ids' => [],
                    'hash' => [],
                ],
            ];
        }

        return [
            'presence' => [
                'count' => $connections->count(),
                'ids' => $connections->map(fn ($connection) => $connection['user_id'])->values()->all(),
                'hash' => $connections->keyBy('user_id')->map->user_info->toArray(),
            ],
        ];
    }
}
