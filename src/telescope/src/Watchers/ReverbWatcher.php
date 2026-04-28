<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Str;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

class ReverbWatcher extends Watcher
{
    /**
     * The entries repository.
     */
    protected ?EntriesRepository $entriesRepository = null;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        if (! class_exists(\Hypervel\Reverb\Events\MessageReceived::class)) {
            return;
        }

        $this->entriesRepository = $app->make(EntriesRepository::class);

        $events = $app->make(Dispatcher::class);
        $listen = $this->options['events'] ?? $this->defaultEvents();
        $eventMap = $this->eventMap();

        foreach ($listen as $eventName) {
            if (isset($eventMap[$eventName])) {
                $events->listen(
                    $eventMap[$eventName],
                    fn ($event) => $this->recordReverbEvent($eventName, $event)
                );
            }
        }
    }

    /**
     * Record a Reverb event.
     *
     * Preserves outer recording state because Reverb events can fire inside
     * an already-recording HTTP request or queue job coroutine (e.g. MessageSent
     * during a broadcast triggered by EventsController). Only starts/stops
     * recording when no outer context is active (pure WebSocket coroutines).
     */
    protected function recordReverbEvent(string $eventName, object $event): void
    {
        $wasRecording = Telescope::isRecording();

        if (! $wasRecording) {
            Telescope::startRecording();
        }

        if (! Telescope::isRecording()) {
            return;
        }

        try {
            Telescope::recordReverb(
                IncomingEntry::make($this->extractEventData($eventName, $event))
                    ->tags($this->extractTags($event))
            );
        } finally {
            if (! $wasRecording) {
                Telescope::store($this->entriesRepository);
                Telescope::stopRecording();
            }
        }
    }

    /**
     * Extract entry content from the event.
     */
    protected function extractEventData(string $eventName, object $event): array
    {
        return match ($eventName) {
            'connection_established' => [
                'event' => $eventName,
                'socket_id' => $event->connection->id(),
                'app_id' => $event->connection->app()->id(),
                'origin' => $event->connection->origin(),
            ],
            'connection_closed' => [
                'event' => $eventName,
                'socket_id' => $event->connection->id(),
                'app_id' => $event->connection->app()->id(),
            ],
            'channel_created', 'channel_removed' => [
                'event' => $eventName,
                'channel' => $event->channel->name(),
            ],
            'connection_pruned' => [
                'event' => $eventName,
                'socket_id' => $event->connection->id(),
                'app_id' => $event->connection->app()->id(),
            ],
            'message_received' => [
                'event' => $eventName,
                'socket_id' => $event->connection->id(),
                'app_id' => $event->connection->app()->id(),
                'message' => $this->truncateMessage($event->message),
            ],
            'message_sent' => [
                'event' => $eventName,
                'socket_id' => $event->connection->id(),
                'app_id' => $event->connection->app()->id(),
                'message' => $this->truncateMessage($event->message),
            ],
            default => ['event' => $eventName],
        };
    }

    /**
     * Extract tags for the entry.
     */
    protected function extractTags(object $event): array
    {
        $tags = [];

        if (property_exists($event, 'connection')) {
            $tags[] = 'App:' . $event->connection->app()->id();
        }

        if (property_exists($event, 'channel')) {
            $tags[] = 'Channel:' . $event->channel->name();
        }

        return $tags;
    }

    /**
     * Truncate message content to the configured size limit.
     */
    protected function truncateMessage(string $message): string
    {
        $limit = $this->options['message_size_limit'] ?? 64;

        return Str::limit($message, $limit * 1024);
    }

    /**
     * Map config event names to Reverb event classes.
     */
    protected function eventMap(): array
    {
        return [
            'connection_established' => \Hypervel\Reverb\Events\ConnectionEstablished::class,
            'connection_closed' => \Hypervel\Reverb\Events\ConnectionClosed::class,
            'channel_created' => \Hypervel\Reverb\Events\ChannelCreated::class,
            'channel_removed' => \Hypervel\Reverb\Events\ChannelRemoved::class,
            'connection_pruned' => \Hypervel\Reverb\Events\ConnectionPruned::class,
            'message_received' => \Hypervel\Reverb\Events\MessageReceived::class,
            'message_sent' => \Hypervel\Reverb\Events\MessageSent::class,
        ];
    }

    /**
     * Default events to record.
     */
    protected function defaultEvents(): array
    {
        return [
            'connection_established',
            'connection_closed',
            'channel_created',
            'channel_removed',
            'connection_pruned',
        ];
    }
}
