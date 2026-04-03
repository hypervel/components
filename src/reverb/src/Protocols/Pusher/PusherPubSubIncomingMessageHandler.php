<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;

class PusherPubSubIncomingMessageHandler implements PubSubIncomingMessageHandler
{
    /**
     * Registered event listeners.
     *
     * @var array<string, list<callable>>
     */
    protected array $events = [];

    /**
     * Handle an incoming message from the pub/sub provider.
     *
     * Uses scalar payloads — resolves Application from ApplicationProvider
     * instead of unserializing objects.
     */
    public function handle(string $payload): void
    {
        $event = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);

        $this->processEventListeners($event);

        // Only resolve the application for event types that need it.
        // Metric response events (type = random key) are handled by
        // processEventListeners above and don't have an app_id field.
        match ($event['type'] ?? null) {
            'message' => $this->handleMessage($event),
            'metrics_request' => $this->handleMetricsRequest($event),
            'terminate' => $this->handleTerminate($event),
            default => null,
        };
    }

    /**
     * Handle a broadcast message from another node.
     */
    protected function handleMessage(array $event): void
    {
        $application = $this->resolveApplication($event['app_id']);

        $except = isset($event['socket_id'])
            ? app(ChannelManager::class)->for($application)->connections()[$event['socket_id']] ?? null
            : null;

        // Redis already delivered this message to every worker on every node.
        // Pass fanOut: false to prevent pipe fan-out to sibling workers,
        // which would cause duplicate delivery in scaling + multi-worker mode.
        if ($event['internal'] ?? false) {
            EventDispatcher::dispatchInternallySynchronously(
                $application,
                $event['payload'],
                $except?->connection(),
                fanOut: false,
            );
        } else {
            EventDispatcher::dispatchSynchronously(
                $application,
                $event['payload'],
                $except?->connection(),
                fanOut: false,
            );
        }
    }

    /**
     * Handle a metrics request from another node.
     */
    protected function handleMetricsRequest(array $event): void
    {
        $application = $this->resolveApplication($event['app_id']);

        $metric = new PendingMetric(
            $event['request_id'],
            $application,
            MetricType::from($event['metric_type']),
            $event['options'] ?? [],
        );

        app(MetricsHandler::class)->publish($metric);
    }

    /**
     * Terminate all connections for the given user.
     */
    protected function handleTerminate(array $event): void
    {
        $application = $this->resolveApplication($event['app_id']);

        collect(app(ChannelManager::class)->for($application)->connections())
            ->each(function ($connection) use ($event) {
                if ((string) ($connection->data()['user_id'] ?? '') === $event['user_id']) {
                    $connection->disconnect();
                }
            });
    }

    /**
     * Resolve the application instance from an app ID.
     */
    protected function resolveApplication(string $appId): Application
    {
        return app(ApplicationProvider::class)->findById($appId);
    }

    /**
     * Process the given event through registered listeners.
     */
    protected function processEventListeners(array $event): void
    {
        foreach ($this->events as $eventName => $listeners) {
            if (($event['type'] ?? null) === $eventName) {
                foreach ($listeners as $listener) {
                    $listener($event);
                }
            }
        }
    }

    /**
     * Listen for the given event.
     */
    public function listen(string $event, callable $callback): void
    {
        $this->events[$event][] = $callback;
    }

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void
    {
        unset($this->events[$event]);
    }
}
