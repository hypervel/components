<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels\Concerns;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\Protocols\Pusher\MetricType;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;

trait InteractsWithPresenceChannels
{
    use InteractsWithPrivateChannels;

    /**
     * Subscribe to the given channel.
     */
    public function subscribe(Connection $connection, ?string $auth = null, ?string $data = null, ?string $userId = null): void
    {
        $this->verify($connection, $auth, $data);

        $userData = $this->decodeSubscriptionData($data);
        $presenceUserId = (string) ($userData['user_id'] ?? '');

        $result = $this->subscribeToChannel(
            $connection,
            $userData,
            $presenceUserId === '' ? null : $presenceUserId,
        );

        if ($result === null) {
            return;
        }

        $this->afterSubscribed($connection, $result);

        if ($result->memberAdded || $presenceUserId === '') {
            // Internal protocol event always fires — other connected clients
            // need to see the member join for accurate presence tracking.
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

            // Cancel any pending deferred member_removed webhook and consume
            // the shared smoothing marker. If either was present, suppress the
            // member_added webhook — the member never truly left.
            $suppressMemberAdded = false;
            $app = $connection->app();

            if ($presenceUserId !== '' && $app->hasWebhooks()) {
                $smoothingMs = (int) ($app->webhooks()['disconnect_smoothing_ms'] ?? 3000);

                $cancelledLocally = app(DeferredWebhookManager::class)->cancelMemberRemoved(
                    $app->id(),
                    $this->name(),
                    $presenceUserId,
                );

                $consumedMarker = $smoothingMs > 0
                    && app(SharedState::class)->clearMemberSmoothingPending($app->id(), $this->name(), $presenceUserId, $smoothingMs);

                $suppressMemberAdded = $cancelledLocally || $consumedMarker;
            }

            if (! $suppressMemberAdded) {
                app(WebhookDispatcher::class)->dispatch($connection->app(), 'member_added', [
                    'channel' => $this->name(),
                    'user_id' => $presenceUserId,
                ]);
            }
        }
    }

    /**
     * Unsubscribe from the given channel.
     */
    public function unsubscribe(Connection $connection, ?string $userId = null): void
    {
        $subscription = $this->connections->find($connection);
        $presenceUserId = (string) ($subscription?->data('user_id') ?? '');

        $result = $this->unsubscribeFromChannel(
            $connection,
            $presenceUserId === '' ? null : $presenceUserId,
        );

        if ($result === null) {
            return;
        }

        $this->afterUnsubscribed($connection, $result);

        if ($presenceUserId === '') {
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

            $app = $connection->app();

            if ($app->hasWebhooks()) {
                $delayMs = (int) ($app->webhooks()['disconnect_smoothing_ms'] ?? 3000);
                $manager = app(DeferredWebhookManager::class);

                if ($delayMs > 0 && $connection->isDisconnecting() && ! $manager->isDraining()) {
                    app(SharedState::class)->setMemberSmoothingPending($app->id(), $this->name(), $presenceUserId, $delayMs);
                    $manager->deferMemberRemoved($app, $this->name(), $presenceUserId, $delayMs / 1000.0, $delayMs);
                } else {
                    app(WebhookDispatcher::class)->dispatch($app, 'member_removed', [
                        'channel' => $this->name(),
                        'user_id' => $presenceUserId,
                    ]);
                }
            }
        }
    }

    /**
     * Get the data associated with the channel.
     */
    public function data(): array
    {
        $connection = collect($this->connections->all())->first();

        if ($connection === null) {
            return [
                'presence' => [
                    'count' => 0,
                    'ids' => [],
                    'hash' => [],
                ],
            ];
        }

        $snapshot = app(MetricsHandler::class)->gather(
            $connection->app(),
            MetricType::Presence->value,
            ['channel' => $this->name()],
        );
        $connections = collect($snapshot['users']);

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
