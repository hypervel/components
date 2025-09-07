<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use BackedEnum;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hypervel\Queue\CallQueuedClosure;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use Throwable;

use function Hypervel\Support\enum_value;

trait Queueable
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
     * The number of seconds before the job should be made available.
     */
    public array|DateInterval|DateTimeInterface|int|null $delay = null;

    /**
     * Indicates whether the job should be dispatched after all database transactions have committed.
     */
    public ?bool $afterCommit = null;

    /**
     * The middleware the job should be dispatched through.
     */
    public array $middleware = [];

    /**
     * The jobs that should run if this job is successful.
     */
    public array $chained = [];

    /**
     * The name of the connection the chain should be sent to.
     */
    public ?string $chainConnection = null;

    /**
     * The name of the queue the chain should be sent to.
     */
    public ?string $chainQueue = null;

    /**
     * The callbacks to be executed on chain failure.
     */
    public ?array $chainCatchCallbacks = null;

    /**
     * Set the desired connection for the job.
     */
    public function onConnection(BackedEnum|string|null $connection): static
    {
        $this->connection = enum_value($connection);

        return $this;
    }

    /**
     * Set the desired queue for the job.
     */
    public function onQueue(BackedEnum|string|null $queue): static
    {
        $this->queue = enum_value($queue);

        return $this;
    }

    /**
     * Set the desired connection for the chain.
     */
    public function allOnConnection(BackedEnum|string|null $connection): static
    {
        $resolvedConnection = enum_value($connection);

        $this->chainConnection = $resolvedConnection;
        $this->connection = $resolvedConnection;

        return $this;
    }

    /**
     * Set the desired queue for the chain.
     */
    public function allOnQueue(BackedEnum|string|null $queue): static
    {
        $resolvedQueue = enum_value($queue);

        $this->chainQueue = $resolvedQueue;
        $this->queue = $resolvedQueue;

        return $this;
    }

    /**
     * Set the desired delay in seconds for the job.
     */
    public function delay(array|DateInterval|DateTimeInterface|int|null $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Set the delay for the job to zero seconds.
     */
    public function withoutDelay(): static
    {
        $this->delay = 0;

        return $this;
    }

    /**
     * Indicate that the job should be dispatched after all database transactions have committed.
     */
    public function afterCommit(): static
    {
        $this->afterCommit = true;

        return $this;
    }

    /**
     * Indicate that the job should not wait until database transactions have been committed before dispatching.
     */
    public function beforeCommit(): static
    {
        $this->afterCommit = false;

        return $this;
    }

    /**
     * Specify the middleware the job should be dispatched through.
     */
    public function through(array|object $middleware): static
    {
        $this->middleware = Arr::wrap($middleware);

        return $this;
    }

    /**
     * Set the jobs that should run if this job is successful.
     */
    public function chain(array $chain): static
    {
        $jobs = ChainedBatch::prepareNestedBatches(collect($chain));

        $this->chained = $jobs->map(function ($job) {
            return $this->serializeJob($job);
        })->all();

        return $this;
    }

    /**
     * Prepend a job to the current chain so that it is run after the currently running job.
     */
    public function prependToChain(mixed $job): static
    {
        $jobs = ChainedBatch::prepareNestedBatches(collect([$job]));

        $this->chained = Arr::prepend($this->chained, $this->serializeJob($jobs->first()));

        return $this;
    }

    /**
     * Append a job to the end of the current chain.
     */
    public function appendToChain(mixed $job): static
    {
        $jobs = ChainedBatch::prepareNestedBatches(collect([$job]));

        $this->chained = array_merge($this->chained, [$this->serializeJob($jobs->first())]);

        return $this;
    }

    /**
     * Serialize a job for queuing.
     *
     * @throws RuntimeException
     */
    protected function serializeJob(mixed $job): string
    {
        if ($job instanceof Closure) {
            if (! class_exists(CallQueuedClosure::class)) {
                throw new RuntimeException(
                    'To enable support for closure jobs, please install the hypervel/queue package.'
                );
            }

            $job = CallQueuedClosure::create($job);
        }

        return serialize($job);
    }

    /**
     * Dispatch the next job on the chain.
     */
    public function dispatchNextJobInChain(): void
    {
        if (! empty($this->chained)) {
            dispatch(tap(unserialize(array_shift($this->chained)), function ($next) {
                $next->chained = $this->chained;

                $next->onConnection($next->connection ?: $this->chainConnection);
                $next->onQueue($next->queue ?: $this->chainQueue);

                $next->chainConnection = $this->chainConnection;
                $next->chainQueue = $this->chainQueue;
                $next->chainCatchCallbacks = $this->chainCatchCallbacks;
            }));
        }
    }

    /**
     * Invoke all of the chain's failed job callbacks.
     */
    public function invokeChainCatchCallbacks(Throwable $e): void
    {
        Collection::make($this->chainCatchCallbacks)->each(function ($callback) use ($e) {
            $callback($e);
        });
    }

    /**
     * Assert that the job has the given chain of jobs attached to it.
     */
    public function assertHasChain(array $expectedChain): void
    {
        PHPUnit::assertTrue(
            collect($expectedChain)->isNotEmpty(),
            'The expected chain can not be empty.'
        );

        if (collect($expectedChain)->contains(fn ($job) => is_object($job))) {
            $expectedChain = collect($expectedChain)->map(fn ($job) => serialize($job))->all();
        } else {
            $chain = collect($this->chained)->map(fn ($job) => get_class(unserialize($job)))->all();
        }

        PHPUnit::assertTrue(
            $expectedChain === ($chain ?? $this->chained),
            'The job does not have the expected chain.'
        );
    }

    /**
     * Assert that the job has no remaining chained jobs.
     */
    public function assertDoesntHaveChain(): void
    {
        PHPUnit::assertEmpty($this->chained, 'The job has chained jobs.');
    }
}
