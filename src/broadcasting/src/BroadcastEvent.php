<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Support\Arr;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

class BroadcastEvent implements ShouldQueue
{
    use Queueable;
    use ReadsQueueAttributes;

    /**
     * The event instance.
     */
    public mixed $event;

    /**
     * The number of times the job may be attempted.
     */
    public ?int $tries;

    /**
     * The number of seconds the job can run before timing out.
     */
    public ?int $timeout;

    /**
     * The number of seconds to wait before retrying the job when encountering an uncaught exception.
     */
    public ?int $backoff;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public ?int $maxExceptions;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job handler instance.
     */
    public function __construct(mixed $event)
    {
        $this->event = $event;
        $this->tries = $this->getAttributeValue($event, Tries::class, 'tries');
        $this->timeout = $this->getAttributeValue($event, Timeout::class, 'timeout');
        $this->backoff = $this->getAttributeValue($event, Backoff::class, 'backoff');
        $this->afterCommit = property_exists($event, 'afterCommit') ? $event->afterCommit : null;
        $this->maxExceptions = $this->getAttributeValue($event, MaxExceptions::class, 'maxExceptions');
        $this->deleteWhenMissingModels = $this->getAttributeValue($event, DeleteWhenMissingModels::class, 'deleteWhenMissingModels', $this->deleteWhenMissingModels);
    }

    /**
     * Handle the queued job.
     */
    public function handle(BroadcastingFactory $manager): void
    {
        $name = method_exists($this->event, 'broadcastAs')
            ? $this->event->broadcastAs()
            : get_class($this->event);

        $channels = Arr::wrap($this->event->broadcastOn());

        if (empty($channels)) {
            return;
        }

        $connections = method_exists($this->event, 'broadcastConnections')
            ? $this->event->broadcastConnections()
            : [null];

        $payload = $this->getPayloadFromEvent($this->event);

        foreach ($connections as $connection) {
            $manager->connection($connection)->broadcast(
                $this->getConnectionChannels($channels, $connection),
                $name,
                $this->getConnectionPayload($payload, $connection)
            );
        }
    }

    /**
     * Get the payload for the given event.
     */
    protected function getPayloadFromEvent(mixed $event): array
    {
        if (method_exists($event, 'broadcastWith')
            && ! is_null($payload = $event->broadcastWith())
        ) {
            return array_merge($payload, ['socket' => data_get($event, 'socket')]);
        }

        $payload = [];

        foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $payload[$property->getName()] = $this->formatProperty($property->getValue($event));
        }

        unset($payload['broadcastQueue']);

        return $payload;
    }

    /**
     * Format the given value for a property.
     */
    protected function formatProperty(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return $value;
    }

    /**
     * Get the channels for the given connection.
     */
    protected function getConnectionChannels(array $channels, ?string $connection): array
    {
        return is_array($channels[$connection ?? ''] ?? null)
            ? $channels[$connection ?? '']
            : $channels;
    }

    /**
     * Get the payload for the given connection.
     */
    protected function getConnectionPayload(array $payload, ?string $connection): array
    {
        $connectionPayload = is_array($payload[$connection ?? ''] ?? null)
            ? $payload[$connection ?? '']
            : $payload;

        if (isset($payload['socket'])) {
            $connectionPayload['socket'] = $payload['socket'];
        }

        return $connectionPayload;
    }

    /**
     * Get the middleware for the underlying event.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        if (! method_exists($this->event, 'middleware')) {
            return [];
        }

        return $this->event->middleware();
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $e = null): void
    {
        if (! method_exists($this->event, 'failed')) {
            return;
        }

        $this->event->failed($e);
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return get_class($this->event);
    }

    /**
     * Prepare the instance for cloning.
     */
    public function __clone()
    {
        $this->event = clone $this->event;
    }
}
