<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Str;

class HttpWebhookDispatcher implements WebhookDispatcher
{
    /**
     * Dispatch a webhook for the given event if the app has it configured.
     */
    public function dispatch(Application $application, string $event, array $data = [], ?Connection $connection = null): void
    {
        if (! $application->hasWebhooks()) {
            return;
        }

        $webhooks = $application->webhooks();
        $allowedEvents = $webhooks['events'] ?? [];

        if (! empty($allowedEvents) && ! in_array($event, $allowedEvents, true)) {
            return;
        }

        $channelPrefix = $webhooks['filter']['channel_name_starts_with'] ?? null;
        if ($channelPrefix !== null && isset($data['channel'])) {
            if (! str_starts_with($data['channel'], $channelPrefix)) {
                return;
            }
        }

        $eventData = $this->buildEventData($application, $event, $data, $connection);

        $batchingEnabled = (bool) ($webhooks['batching']['enabled'] ?? false);

        if ($batchingEnabled) {
            $buffer = app(WebhookBatchBuffer::class);
            $shouldSchedule = $buffer->appendAndCheckSchedule($application->id(), $eventData);

            if ($shouldSchedule) {
                FlushWebhookBatchJob::dispatch($application->id(), $webhooks)
                    ->onQueue('reverb-webhook-flush')
                    ->delay(now()->addMilliseconds(
                        (int) ($webhooks['batching']['max_delay_ms'] ?? 250)
                    ));
            }
        } else {
            $payload = new WebhookPayload(
                webhookId: (string) Str::orderedUuid(),
                timeMs: (int) (microtime(true) * 1000),
                events: [$eventData],
            );

            WebhookDeliveryJob::dispatch(
                $payload,
                $webhooks['url'],
                $application->key(),
                $application->secret(),
                (int) ($webhooks['retries'] ?? 3),
                (int) ($webhooks['retry_delay'] ?? 1),
                (int) ($webhooks['timeout'] ?? 5),
                $webhooks['headers'] ?? [],
            );
        }
    }

    /**
     * Build the per-event data array matching the Pusher webhook spec.
     */
    protected function buildEventData(
        Application $application,
        string $event,
        array $data,
        ?Connection $connection,
    ): array {
        $eventData = ['name' => $event];

        if (isset($data['channel'])) {
            $eventData['channel'] = $data['channel'];
        }

        if (isset($data['user_id'])) {
            $eventData['user_id'] = (string) $data['user_id'];
        }

        if ($event === 'client_event') {
            if (isset($data['event'])) {
                $eventData['event'] = $data['event'];
            }

            if (isset($data['data'])) {
                $eventData['data'] = is_string($data['data'])
                    ? $data['data']
                    : json_encode($data['data']);
            }

            if ($connection !== null) {
                $eventData['socket_id'] = $connection->id();
            }

            $this->enrichClientEventUserId($application, $eventData, $connection);
        }

        return $eventData;
    }

    /**
     * Add the authenticated user ID for presence-channel client events.
     *
     * @param array<string, mixed> $eventData
     */
    protected function enrichClientEventUserId(Application $application, array &$eventData, ?Connection $connection): void
    {
        if ($connection === null
            || isset($eventData['user_id'])
            || ! isset($eventData['channel'])
            || ! is_string($eventData['channel'])
            || ! str_starts_with($eventData['channel'], 'presence-')
        ) {
            return;
        }

        $channel = app(ChannelManager::class)->for($application)->find($eventData['channel']);
        $userId = $channel?->find($connection)?->data('user_id');

        if ($userId !== null && $userId !== '') {
            $eventData['user_id'] = (string) $userId;
        }
    }
}
