<?php

declare(strict_types=1);

namespace Hypervel\Events;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Support\Collection;
use Laravel\SerializableClosure\SerializableClosure;
use UnitEnum;

use function Hypervel\Bus\dispatch;
use function Hypervel\Support\enum_value;

class QueuedClosure
{
    /**
     * The underlying Closure.
     */
    public Closure $closure;

    /**
     * The name of the connection the job should be sent to.
     */
    public ?string $connection = null;

    /**
     * The name of the queue the job should be sent to.
     */
    public ?string $queue = null;

    /**
     * The job "group" the job should be sent to.
     */
    public ?string $messageGroup = null;

    /**
     * The job deduplicator callback the job should use to generate the deduplication ID.
     */
    public ?SerializableClosure $deduplicator = null;

    /**
     * The number of seconds before the job should be made available.
     */
    public DateTimeInterface|DateInterval|int|null $delay = null;

    /**
     * All of the "catch" callbacks for the queued closure.
     */
    public array $catchCallbacks = [];

    /**
     * Create a new queued closure event listener resolver.
     */
    public function __construct(Closure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * Set the desired connection for the job.
     */
    public function onConnection(UnitEnum|string|null $connection): static
    {
        $this->connection = enum_value($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     */
    public function onQueue(UnitEnum|string|null $queue): static
    {
        $this->queue = enum_value($queue);

        return $this;
    }

    /**
     * Set the desired job "group".
     *
     * This feature is only supported by some queues, such as Amazon SQS.
     */
    public function onGroup(UnitEnum|string $group): static
    {
        $this->messageGroup = enum_value($group);

        return $this;
    }

    /**
     * Set the desired job deduplicator callback.
     *
     * This feature is only supported by some queues, such as Amazon SQS FIFO.
     */
    public function withDeduplicator(?callable $deduplicator): static
    {
        $this->deduplicator = $deduplicator instanceof Closure
            ? new SerializableClosure($deduplicator)
            : $deduplicator;

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     */
    public function delay(DateTimeInterface|DateInterval|int|null $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Specify a callback that should be invoked if the queued listener job fails.
     */
    public function catch(Closure $closure): static
    {
        $this->catchCallbacks[] = $closure;

        return $this;
    }

    /**
     * Resolve the actual event listener callback.
     */
    public function resolve(): Closure
    {
        return function (...$arguments) {
            dispatch(new CallQueuedListener(InvokeQueuedClosure::class, 'handle', [
                'closure' => new SerializableClosure($this->closure),
                'arguments' => $arguments,
                'catch' => (new Collection($this->catchCallbacks))
                    ->map(fn ($callback) => new SerializableClosure($callback))
                    ->all(),
            ]))
                ->onConnection($this->connection)
                ->onQueue($this->queue)
                ->delay($this->delay)
                ->onGroup($this->messageGroup)
                ->withDeduplicator($this->deduplicator?->getClosure());
        };
    }
}
