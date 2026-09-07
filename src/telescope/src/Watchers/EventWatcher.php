<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Closure;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\Dispatcher;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Telescope\ExtractProperties;
use Hypervel\Telescope\ExtractTags;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\JsonNormalizer;
use Hypervel\Telescope\Telescope;
use ReflectionFunction;

class EventWatcher extends Watcher
{
    use FormatsClosure;

    /**
     * The event dispatcher.
     */
    protected Dispatcher $events;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $this->events = $app->make(Dispatcher::class);

        $this->events->observe('*', [$this, 'recordEvent']);
    }

    /**
     * Record an event was fired.
     */
    public function recordEvent(string $event, array $payload): void
    {
        if (! Telescope::isRecording() || $this->shouldIgnore($event)) {
            return;
        }

        $formattedPayload = $this->extractPayload($event, $payload);

        Telescope::recordEvent(IncomingEntry::make([
            'name' => $event,
            'payload' => empty($formattedPayload) ? null : $formattedPayload,
            'listeners' => $this->formatListeners($event),
            'broadcast' => class_exists($event)
                ? in_array(ShouldBroadcast::class, (array) class_implements($event), true)
                : false,
        ])->tags(class_exists($event) && isset($payload[0]) ? ExtractTags::from($payload[0]) : []));
    }

    /**
     * Extract the payload and tags from the event.
     */
    protected function extractPayload(string $event, array $payload): array
    {
        if (class_exists($event) && isset($payload[0]) && is_object($payload[0])) {
            return ExtractProperties::from($payload[0]);
        }

        // Native encoding captures event object state instead of its published representation.
        return Collection::make($payload)->map(function ($value) {
            return is_object($value) ? [
                'class' => get_class($value),
                'properties' => JsonNormalizer::normalize($value),
            ] : $value;
        })->toArray();
    }

    /**
     * Format list of event listeners.
     */
    protected function formatListeners(string $eventName): array
    {
        return Collection::make($this->events->getListeners($eventName))
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
                if (is_object($listener) && is_callable($listener) && ! $listener instanceof Closure) {
                    return get_class($listener) . '@__invoke';
                }

                return $this->formatClosureListener($listener);
            })->reject(function ($listener) {
                return str_starts_with($listener, 'Hypervel\Telescope');
            })->map(function ($listener) {
                if (Str::contains($listener, '@')
                    && class_exists($class = Str::beforeLast($listener, '@'))) {
                    $queued = in_array(ShouldQueue::class, class_implements($class), true);
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
     * Determine if the event was fired internally by the framework.
     */
    protected function eventIsFiredByTheFramework(string $eventName): bool
    {
        return Str::startsWith($eventName, [
            'Hypervel\\', 'eloquent', 'bootstrapped', 'bootstrapping', 'creating', 'composing',
        ]);
    }

    /**
     * Determine if the event is ignored manually.
     */
    protected function eventIsIgnored(string $eventName): bool
    {
        return Str::is($this->options['ignore'] ?? [], $eventName);
    }
}
