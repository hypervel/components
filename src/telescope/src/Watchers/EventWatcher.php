<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hyperf\Stringable\Str;
use Hypervel\Broadcasting\Contracts\ShouldBroadcast;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Telescope\ExtractProperties;
use Hypervel\Telescope\ExtractTags;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\Traits\FormatsClosure;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionFunction;

class EventWatcher extends Watcher
{
    use FormatsClosure;

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
            ->listen('*', [$this, 'recordEvent']);
    }

    /**
     * Record an event was fired.
     */
    public function recordEvent(object|string $event, ...$payload): void
    {
        $eventName = is_string($event) ? $event : get_class($event);
        if (! Telescope::isRecording() || $this->shouldIgnore($eventName)) {
            return;
        }

        $formattedPayload = $this->extractPayload($event, $payload);

        Telescope::recordEvent(IncomingEntry::make([
            'name' => $eventName,
            'payload' => empty($formattedPayload) ? null : $formattedPayload,
            'listeners' => $this->formatListeners($eventName),
            'broadcast' => class_exists($eventName)
                ? in_array(ShouldBroadcast::class, (array) class_implements($eventName))
                : false,
        ])->tags(class_exists($eventName) && isset($payload[0]) ? ExtractTags::from($payload[0]) : []));
    }

    /**
     * Extract the payload and tags from the event.
     */
    protected function extractPayload(object|string $event, array $payload): array
    {
        if (is_object($event) && empty($payload)) {
            return ExtractProperties::from($event);
        }

        return Collection::make($payload)->map(function ($value) {
            return is_object($value) ? [
                'class' => get_class($value),
                'properties' => json_decode(json_encode($value), true),
            ] : $value;
        })->toArray();
    }

    /**
     * Format list of event listeners.
     */
    protected function formatListeners(string $eventName): array
    {
        /* @phpstan-ignore-next-line */
        return Collection::make(app(EventDispatcherInterface::class)->getListeners($eventName))
            ->map(function ($listener) {
                $listener = (new ReflectionFunction($listener))
                    ->getStaticVariables()['listener'];

                if (is_string($listener)) {
                    return Str::contains($listener, '@') ? $listener : $listener . '@handle';
                }
                if (is_array($listener) && is_string($listener[0])) {
                    return $listener[0] . '@' . $listener[1];
                }
                if (is_array($listener) && is_object($listener[0])) {
                    return get_class($listener[0]) . '@' . $listener[1];
                }

                return $this->formatClosureListener($listener);
            })->reject(function ($listener) {
                return str_starts_with($listener, 'Hypervel\Telescope');
            })->map(function ($listener) {
                if (Str::contains($listener, '@')) {
                    $queued = in_array(ShouldQueue::class, class_implements(Str::beforeLast($listener, '@')));
                }

                return [
                    'name' => $listener,
                    'queued' => $queued ?? false,
                ];
            })->values()->toArray();
    }

    /**
     * Determine if the event should be ignored.
     */
    protected function shouldIgnore(string $eventName): bool
    {
        return $this->eventIsIgnored($eventName)
            || (Telescope::$ignoreFrameworkEvents && $this->eventIsFiredByTheFramework($eventName));
    }

    /**
     * Determine if the event was fired internally by Laravel.
     */
    protected function eventIsFiredByTheFramework(string $eventName): bool
    {
        if (in_array($eventName, ModelWatcher::MODEL_EVENTS)) {
            return true;
        }

        $prefixes = [
            'Hypervel',
            'Hyperf',
            'FriendsOfHyperf',
            'bootstrapped',
            'bootstrapping',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the event is ignored manually.
     */
    protected function eventIsIgnored(string $eventName): bool
    {
        return Str::is($this->options['ignore'] ?? [], $eventName);
    }
}
