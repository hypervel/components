<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\EncryptedPrivateChannel;
use Hypervel\Broadcasting\PresenceChannel;
use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Contracts\Broadcasting\HasBroadcastChannel;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Arr;
use LogicException;
use ReflectionClass;
use Throwable;

use function Hypervel\Support\enum_value;

trait HasLiveUpdates
{
    /**
     * The prefix Hypervel's channel classes add to a channel name, keyed by type.
     */
    protected const array CHANNEL_PREFIXES = [
        'public' => '',
        'private' => 'private-',
        'presence' => 'presence-',
        'encrypted-private' => 'private-encrypted-',
    ];

    /**
     * Indicates if the property should receive live updates.
     */
    protected bool $live = false;

    /**
     * The events and channels each [live] call declared, kept per call so an
     * explicit channel only applies to its own events.
     *
     * @var array<int, array{events: array<int, class-string|object|string>, channels: array<int, Channel|string>}>
     */
    protected array $liveDeclarations = [];

    /**
     * The number of milliseconds to throttle live updates by.
     */
    protected ?int $liveThrottle = null;

    /**
     * Mark the property as live, refreshing it whenever one of the given events
     * is received. Merge properties cannot be live.
     *
     * @param null|array<int, class-string|object|string>|class-string|object|string $on
     * @param null|array<int, Channel|string>|Channel|string $channel
     */
    public function live(array|object|string|null $on = null, array|Channel|string|null $channel = null, ?int $throttle = null): static
    {
        if ($this instanceof Mergeable && $this->shouldMerge()) {
            throw new LogicException('Live updates are not supported on merge properties.');
        }

        $this->live = true;
        $this->liveDeclarations[] = ['events' => Arr::wrap($on), 'channels' => Arr::wrap($channel)];

        if ($throttle !== null) {
            $this->liveThrottle = $throttle;
        }

        return $this;
    }

    /**
     * Determine if the property should receive live updates.
     */
    public function isLive(): bool
    {
        return $this->live;
    }

    /**
     * Get the number of milliseconds to throttle live updates by.
     */
    public function liveThrottle(): ?int
    {
        return $this->liveThrottle;
    }

    /**
     * Get the live-update listeners that should refresh the property. A channel
     * passed to [live] applies only to the events it was passed with.
     *
     * @return array<int, array{channel: array{name: string, type: string}, events: array<int, string>}>
     */
    public function liveListeners(): array
    {
        $hasEvents = collect($this->liveDeclarations)->contains(fn ($declaration) => count($declaration['events']) > 0);

        if (! $hasEvents) {
            throw new LogicException('A live property must listen for at least one event. Pass the event to the [on] argument.');
        }

        $listeners = [];

        foreach ($this->liveDeclarations as $declaration) {
            $hasChannels = count($declaration['channels']) > 0;

            foreach ($declaration['events'] as $event) {
                $eventName = $this->resolveEventName($event);

                $channels = $hasChannels ? $declaration['channels'] : $this->channelsFromEvent($event);

                foreach ($this->resolveChannels($channels) as $channel) {
                    $this->addLiveListener($listeners, $channel, [$eventName]);
                }
            }
        }

        return array_values($listeners);
    }

    /**
     * Resolve the name Hypervel broadcasts the given event as.
     *
     * @param class-string|object|string $event
     */
    protected function resolveEventName(object|string $event): string
    {
        if (is_object($event)) {
            return method_exists($event, 'broadcastAs')
                ? enum_value($event->broadcastAs())
                : $event::class;
        }

        // A missing class would leave a listener that never fires. Names without
        // a namespace separator are literal, and may come from non-Hypervel producers.
        if (str_contains($event, '\\') && ! class_exists($event)) {
            throw new LogicException("The [{$event}] event class does not exist. Pass an existing event class to the [on] argument, or its broadcast name as a string.");
        }

        // A broadcast name is read the same way the channels are, off an event
        // built without its payload, which is all a fixed name needs.
        if (class_exists($event) && (new ReflectionClass($event))->hasMethod('broadcastAs')) {
            try {
                return enum_value((new ReflectionClass($event))->newInstanceWithoutConstructor()->broadcastAs());
            } catch (Throwable $exception) {
                if (Coroutine::causedByCancellation($exception)) {
                    throw $exception;
                }

                throw new LogicException("Unable to resolve the broadcast name for the [{$event}] event because it builds it from its payload. Pass an event instance to the [on] argument instead of its class name.", previous: $exception);
            }
        }

        return $event;
    }

    /**
     * Gather the channels the given event broadcasts on. A class name is built
     * without its payload, which is all an event needs when its channels do not
     * depend on one.
     *
     * @param class-string|object|string $event
     * @return array<int, Channel|HasBroadcastChannel|string>
     */
    protected function channelsFromEvent(object|string $event): array
    {
        if (! is_string($event)) {
            return $this->broadcastChannels($event);
        }

        if (! class_exists($event)) {
            throw new LogicException("Unable to resolve the channels for the [{$event}] event because it was given as a string. Pass the [channel] argument, or pass an event instance to the [on] argument.");
        }

        try {
            return $this->broadcastChannels((new ReflectionClass($event))->newInstanceWithoutConstructor());
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            throw new LogicException("Unable to resolve the channels for the [{$event}] event because it builds them from its payload. Pass an event instance to the [on] argument, or pass the [channel] argument.", previous: $exception);
        }
    }

    /**
     * Read the channels off an event instance.
     *
     * @return array<int, Channel|HasBroadcastChannel|string>
     */
    protected function broadcastChannels(object $event): array
    {
        if (! method_exists($event, 'broadcastOn')) {
            throw new LogicException('The [' . $event::class . '] event does not define a [broadcastOn] method. Pass the [channel] argument instead.');
        }

        $channels = Arr::wrap($event->broadcastOn());

        // Channels keyed by connection are read for the default one, the way
        // Hypervel's broadcast job picks them per connection
        if (collect($channels)->contains(fn ($channel) => is_array($channel))) {
            $channels = $this->channelsForDefaultConnection($event, $channels);
        }

        if (count($channels) === 0) {
            throw new LogicException('The [' . $event::class . '] event does not broadcast on any channels. Pass the [channel] argument instead.');
        }

        return $channels;
    }

    /**
     * Get the channels the given event broadcasts on for the default connection.
     *
     * @param array<array-key, mixed> $channels
     * @return array<int, Channel|HasBroadcastChannel|string>
     */
    protected function channelsForDefaultConnection(object $event, array $channels): array
    {
        $connection = config()->string('broadcasting.default');

        if (! is_array($channels[$connection] ?? null)) {
            throw new LogicException('The [' . $event::class . "] event keys its channels by connection, but has none for the default [{$connection}] connection. Pass the [channel] argument instead.");
        }

        return array_values($channels[$connection]);
    }

    /**
     * Add the given events to the listener for the given channel.
     *
     * @param array<string, array{channel: array{name: string, type: string}, events: array<int, string>}> $listeners
     * @param array{name: string, type: string} $channel
     * @param array<int, string> $events
     */
    protected function addLiveListener(array &$listeners, array $channel, array $events): void
    {
        $key = "{$channel['type']}:{$channel['name']}";

        $listeners[$key] ??= [
            'channel' => $channel,
            'events' => [],
        ];

        foreach ($events as $event) {
            if (! in_array($event, $listeners[$key]['events'], true)) {
                $listeners[$key]['events'][] = $event;
            }
        }
    }

    /**
     * Resolve the given channels into the unprefixed names and types the broadcaster sends them on.
     *
     * @param array<int, Channel|HasBroadcastChannel|string> $channels
     * @return array<int, array{name: string, type: string}>
     */
    protected function resolveChannels(array $channels): array
    {
        $formatter = Broadcaster::channelFormatter();

        if ($formatter === null) {
            return array_map($this->resolveChannel(...), $channels);
        }

        // Hypervel's outgoing channel formatter renames every channel before the broadcaster
        // sends it, so clients must subscribe to the formatted names. Formatted channels are
        // strings, so the type is read back from the prefix the broadcaster sends them with.
        // A HasBroadcastChannel value is formatted under its channel name, as resolveChannel() reads it.
        $channels = array_map(
            fn (Channel|HasBroadcastChannel|string $channel): Channel|string => $channel instanceof HasBroadcastChannel
                ? $channel->broadcastChannel()
                : $channel,
            $channels,
        );

        $resolved = [];

        foreach ($formatter($channels) as $channel) {
            $channel = (string) $channel;

            $type = match (true) {
                str_starts_with($channel, self::CHANNEL_PREFIXES['encrypted-private']) => 'encrypted-private',
                str_starts_with($channel, self::CHANNEL_PREFIXES['private']) => 'private',
                str_starts_with($channel, self::CHANNEL_PREFIXES['presence']) => 'presence',
                default => 'public',
            };

            $resolved[] = [
                'name' => substr($channel, strlen(self::CHANNEL_PREFIXES[$type])),
                'type' => $type,
            ];
        }

        return $resolved;
    }

    /**
     * Resolve the given channel into its unprefixed name and type. Hypervel's
     * prefixes follow Pusher's convention, so transports get the type separately
     * and apply their own naming.
     *
     * @return array{name: string, type: string}
     */
    protected function resolveChannel(Channel|HasBroadcastChannel|string $channel): array
    {
        if ($channel instanceof HasBroadcastChannel) {
            $channel = $channel->broadcastChannel();
        }

        if (is_string($channel)) {
            // Hypervel's broadcaster casts a channel to a string without adding a
            // prefix, so a bare string is a public channel wherever it is given.
            // Use `new PrivateChannel(...)` to subscribe privately.
            return ['name' => $channel, 'type' => 'public'];
        }

        $type = $this->channelType($channel);

        return [
            'name' => $this->channelName($channel, $type),
            'type' => $type,
        ];
    }

    /**
     * Resolve the type of the given channel.
     */
    protected function channelType(Channel $channel): string
    {
        return match (true) {
            $channel instanceof EncryptedPrivateChannel => 'encrypted-private',
            $channel instanceof PresenceChannel => 'presence',
            $channel instanceof PrivateChannel => 'private',
            default => 'public',
        };
    }

    /**
     * Resolve the name of the given channel, without the prefix Hypervel's channel class added to it.
     */
    protected function channelName(Channel $channel, string $type): string
    {
        $prefix = self::CHANNEL_PREFIXES[$type];

        return $prefix !== '' && str_starts_with($channel->name, $prefix)
            ? substr($channel->name, strlen($prefix))
            : $channel->name;
    }
}
