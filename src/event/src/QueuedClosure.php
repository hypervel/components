<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Events\CallQueuedListener;
use Laravel\SerializableClosure\SerializableClosure;
use UnitEnum;

use function Hypervel\Bus\dispatch;
use function Hypervel\Support\enum_value;

class QueuedClosure
{
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
     * The number of seconds before the job should be made available.
     */
    public DateInterval|DateTimeInterface|int|null $delay = null;

    /**
     * All of the "catch" callbacks for the queued closure.
     */
    public array $catchCallbacks = [];

    /**
     * Create a new queued closure event listener resolver.
     *
     * @param Closure $closure The underlying Closure
     */
    public function __construct(public Closure $closure)
    {
    }

    /**
     * Set the desired connection for the job.
     */
    public function onConnection(UnitEnum|string|null $connection): static
    {
        $this->connection = is_null($connection) ? null : (string) enum_value($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     */
    public function onQueue(UnitEnum|string|null $queue): static
    {
        $this->queue = is_null($queue) ? null : (string) enum_value($queue);

        return $this;
    }

    /**
     * Set the desired job "group".
     *
     * This feature is only supported by some queues, such as Amazon SQS.
     */
    public function onGroup(UnitEnum|string $group): static
    {
        $this->messageGroup = (string) enum_value($group);

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     */
    public function delay(?int $delay): static
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
                'catch' => collect($this->catchCallbacks)->map(function ($callback) {
                    return new SerializableClosure($callback);
                })->all(),
            ]))->onConnection($this->connection)->onQueue($this->queue)->delay($this->delay);
        };
    }
}
