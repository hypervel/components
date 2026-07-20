<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bus;

use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\UniqueJobPayloadContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Queue\PreparesForDispatch;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Support\Traits\Conditionable;
use LogicException;
use UnitEnum;

class PendingDispatch
{
    use Conditionable;
    use ReadsQueueAttributes;

    /**
     * Indicates if the job should be dispatched immediately after sending the response.
     */
    protected bool $afterResponse = false;

    /**
     * Create a new pending job dispatch.
     */
    public function __construct(
        protected mixed $job
    ) {
    }

    /**
     * Set the desired connection for the job.
     */
    public function onConnection(UnitEnum|string|null $connection): static
    {
        $this->job->onConnection($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     */
    public function onQueue(UnitEnum|string|null $queue): static
    {
        $this->job->onQueue($queue);

        return $this;
    }

    /**
     * Set the desired job message group.
     *
     * This feature is only supported by some queues, such as Amazon SQS.
     */
    public function onGroup(array|UnitEnum|string|int|null $group): static
    {
        if (! is_null($group)) {
            $this->job->onGroup($group);
        }

        return $this;
    }

    /**
     * Set the desired job deduplicator callback.
     *
     * This feature is only supported by some queues, such as Amazon SQS FIFO.
     */
    public function withDeduplicator(array|callable|null $deduplicator): static
    {
        $this->job->withDeduplicator($deduplicator);

        return $this;
    }

    /**
     * Set the desired connection for the chain.
     */
    public function allOnConnection(UnitEnum|string|null $connection): static
    {
        $this->job->allOnConnection($connection);

        return $this;
    }

    /**
     * Set the desired queue for the chain.
     */
    public function allOnQueue(UnitEnum|string|null $queue): static
    {
        $this->job->allOnQueue($queue);

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     */
    public function delay(DateInterval|DateTimeInterface|int|null $delay): static
    {
        $this->job->delay($delay);

        return $this;
    }

    /**
     * Set the delay for the job to zero seconds.
     */
    public function withoutDelay(): static
    {
        $this->job->withoutDelay();

        return $this;
    }

    /**
     * Indicate that the job should be dispatched after all database transactions have committed.
     */
    public function afterCommit(): static
    {
        $this->job->afterCommit();

        return $this;
    }

    /**
     * Indicate that the job should not wait until database transactions have been committed before dispatching.
     */
    public function beforeCommit(): static
    {
        $this->job->beforeCommit();

        return $this;
    }

    /**
     * Set the jobs that should run if this job is successful.
     */
    public function chain(array $chain): static
    {
        $this->job->chain($chain);

        return $this;
    }

    /**
     * Indicate that the job should be dispatched after the response is sent to the browser.
     */
    public function afterResponse(bool $afterResponse = true): static
    {
        $this->afterResponse = $afterResponse;

        return $this;
    }

    /**
     * Get the underlying job instance.
     */
    public function getJob(): mixed
    {
        return $this->job;
    }

    /**
     * Determine if the job should be dispatched.
     */
    protected function shouldDispatch(): bool
    {
        if ($this->job instanceof PreparesForDispatch && $this->job->prepareForDispatch() === false) {
            return false;
        }

        if (! $this->job instanceof ShouldBeUnique) {
            return true;
        }

        if ($this->getAttributeValue($this->job, DebounceFor::class, 'debounceFor') !== null) {
            throw new LogicException('A debounced job cannot also implement ShouldBeUnique.');
        }

        $cache = Container::getInstance()
            ->make(Cache::class);

        return (new UniqueLock($cache))
            ->acquire($this->job);
    }

    /**
     * Acquire a debounce lock for the job and set its delay.
     *
     * @throws LogicException
     */
    protected function acquireDebounceLock(): void
    {
        /** @var ?int $debounceFor */
        $debounceFor = $this->getAttributeValue($this->job, DebounceFor::class, 'debounceFor');

        if ($debounceFor === null) {
            return;
        }

        $result = (new DebounceLock(Container::getInstance()->make(Cache::class)))
            ->acquire($this->job, $debounceFor);

        $this->job->debounceOwner = $result['owner'];

        if (is_null($this->job->delay)) {
            $this->job->delay = $result['maxWaitExceeded'] ? 0 : $debounceFor;
        }
    }

    /**
     * Dynamically proxy methods to the underlying job.
     */
    public function __call(string $method, array $parameters): static
    {
        $this->job->{$method}(...$parameters);

        return $this;
    }

    /**
     * Handle the object's destruction.
     */
    public function __destruct()
    {
        if (! $this->shouldDispatch()) {
            return;
        }

        if ($this->job instanceof ShouldBeUnique) {
            UniqueJobPayloadContext::register($this->job);
        }

        $this->acquireDebounceLock();

        if ($this->afterResponse) {
            Container::getInstance()
                ->make(Dispatcher::class)
                ->dispatchAfterResponse($this->job);
        } else {
            Container::getInstance()
                ->make(Dispatcher::class)
                ->dispatch($this->job);
        }
    }
}
