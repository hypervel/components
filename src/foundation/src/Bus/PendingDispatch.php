<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bus;

use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use UnitEnum;

class PendingDispatch
{
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
    public function afterResponse(): static
    {
        $this->afterResponse = true;

        return $this;
    }

    /**
     * Determine if the job should be dispatched.
     */
    protected function shouldDispatch(): bool
    {
        if (! $this->job instanceof ShouldBeUnique) {
            return true;
        }

        $cache = Container::getInstance()
            ->make(CacheFactory::class);

        return (new UniqueLock($cache))
            ->acquire($this->job);
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
