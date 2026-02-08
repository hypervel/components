<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Queue\CallQueuedClosure;
use Laravel\SerializableClosure\SerializableClosure;
use UnitEnum;

use function Hyperf\Support\value;
use function Hypervel\Support\enum_value;

class PendingChain
{
    use Conditionable;

    /**
     * The name of the connection the chain should be sent to.
     */
    public ?string $connection = null;

    /**
     * The name of the queue the chain should be sent to.
     */
    public ?string $queue = null;

    /**
     * The number of seconds before the chain should be made available.
     */
    public DateInterval|DateTimeInterface|int|null $delay = null;

    /**
     * The callbacks to be executed on failure.
     */
    public array $catchCallbacks = [];

    /**
     * Create a new PendingChain instance.
     *
     * @param mixed $job the class name of the job being dispatched
     * @param array $chain the jobs to be chained
     */
    public function __construct(
        public mixed $job,
        public array $chain
    ) {
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
     * Set the desired delay in seconds for the chain.
     */
    public function delay(DateInterval|DateTimeInterface|int|null $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Add a callback to be executed on job failure.
     */
    public function catch(callable $callback): static
    {
        $this->catchCallbacks[] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

        return $this;
    }

    /**
     * Get the "catch" callbacks that have been registered.
     */
    public function catchCallbacks(): array
    {
        return $this->catchCallbacks ?? [];
    }

    /**
     * Dispatch the job chain.
     */
    public function dispatch(): PendingDispatch
    {
        if (is_string($this->job)) {
            $firstJob = new $this->job(...func_get_args());
        } elseif ($this->job instanceof Closure) {
            $firstJob = CallQueuedClosure::create($this->job);
        } else {
            $firstJob = $this->job;
        }

        if ($this->connection) {
            $firstJob->chainConnection = $this->connection;
            $firstJob->connection = $firstJob->connection ?: $this->connection;
        }

        if ($this->queue) {
            $firstJob->chainQueue = $this->queue;
            $firstJob->queue = $firstJob->queue ?: $this->queue;
        }

        if ($this->delay) {
            $firstJob->delay = ! is_null($firstJob->delay) ? $firstJob->delay : $this->delay;
        }

        $firstJob->chain($this->chain);
        $firstJob->chainCatchCallbacks = $this->catchCallbacks();

        return ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->dispatch($firstJob);
    }

    /**
     * Dispatch the job chain if the given truth test passes.
     */
    public function dispatchIf(bool|Closure $boolean): ?PendingDispatch
    {
        return value($boolean) ? $this->dispatch() : null;
    }

    /**
     * Dispatch the job chain unless the given truth test passes.
     */
    public function dispatchUnless(bool|Closure $boolean): ?PendingDispatch
    {
        return ! value($boolean) ? $this->dispatch() : null;
    }
}
