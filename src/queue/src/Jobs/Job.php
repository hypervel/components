<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\Contracts\BatchRepository;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Exceptions\ManuallyFailedException;
use Hypervel\Queue\Exceptions\TimeoutExceededException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

abstract class Job implements JobContract
{
    use InteractsWithTime;

    /**
     * The job handler instance.
     */
    protected mixed $instance;

    /**
     * The IoC container instance.
     */
    protected ContainerInterface $container;

    /**
     * Indicates if the job has been deleted.
     */
    protected bool $deleted = false;

    /**
     * Indicates if the job has been released.
     */
    protected bool $released = false;

    /**
     * Indicates if the job has failed.
     */
    protected bool $failed = false;

    /**
     * The name of the connection the job belongs to.
     */
    protected string $connectionName;

    /**
     * The name of the queue the job belongs to.
     */
    protected ?string $queue;

    /**
     * Get the job identifier.
     */
    abstract public function getJobId(): null|int|string;

    /**
     * Get the raw body of the job.
     */
    abstract public function getRawBody(): string;

    /**
     * Get the UUID of the job.
     */
    public function uuid(): ?string
    {
        return $this->payload()['uuid'] ?? null;
    }

    /**
     * Fire the job.
     */
    public function fire(): void
    {
        $payload = $this->payload();

        [$class, $method] = JobName::parse($payload['job']);

        ($this->instance = $this->resolve($class))->{$method}($this, $payload['data']);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * Determine if the job has been deleted.
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        $this->released = true;
    }

    /**
     * Determine if the job was released back into the queue.
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Determine if the job has been deleted or released.
     */
    public function isDeletedOrReleased(): bool
    {
        return $this->isDeleted() || $this->isReleased();
    }

    /**
     * Determine if the job has been marked as a failure.
     */
    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * Mark the job as "failed".
     */
    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    /**
     * Delete the job, call the "failed" method, and raise the failed job event.
     */
    public function fail(?Throwable $e = null): void
    {
        $this->markAsFailed();

        if ($this->isDeleted()) {
            return;
        }

        $commandName = $this->payload()['data']['commandName'] ?? false;

        // If the exception is due to a job timing out, we need to rollback the current
        // database transaction so that the failed job count can be incremented with
        // the proper value. Otherwise, the current transaction will never commit.
        if ($e instanceof TimeoutExceededException
            && $commandName
            && in_array(Batchable::class, class_uses_recursive($commandName))
        ) {
            $batchRepository = $this->resolve(BatchRepository::class);

            try {
                $batchRepository->rollBack();
            } catch (Throwable $e) {
                // ...
            }
        }

        try {
            // If the job has failed, we will delete it, call the "failed" method and then call
            // an event indicating the job has failed so it can be logged if needed. This is
            // to allow every developer to better keep monitor of their failed queue jobs.
            $this->delete();

            $this->failed($e);
        } finally {
            $this->resolve(EventDispatcherInterface::class)
                ->dispatch(new JobFailed(
                    $this->connectionName,
                    $this,
                    $e ?: new ManuallyFailedException()
                ));
        }
    }

    /**
     * Process an exception that caused the job to fail.
     */
    protected function failed(?Throwable $e): void
    {
        $payload = $this->payload();

        [$class, $method] = JobName::parse($payload['job']);

        if (method_exists($this->instance = $this->resolve($class), 'failed')) {
            $this->instance->failed($payload['data'], $e, $payload['uuid'] ?? '');
        }
    }

    /**
     * Resolve the given class.
     */
    protected function resolve(string $class): mixed
    {
        return $this->container->get($class);
    }

    /**
     * Get the resolved job handler instance.
     */
    public function getResolvedJob(): mixed
    {
        return $this->instance;
    }

    /**
     * Get the decoded body of the job.
     */
    public function payload(): array
    {
        return json_decode($this->getRawBody(), true);
    }

    /**
     * Get the number of times to attempt a job.
     */
    public function maxTries(): ?int
    {
        return $this->payload()['maxTries'] ?? null;
    }

    /**
     * Get the number of times to attempt a job after an exception.
     */
    public function maxExceptions(): ?int
    {
        return $this->payload()['maxExceptions'] ?? null;
    }

    /**
     * Determine if the job should fail when it timeouts.
     */
    public function shouldFailOnTimeout(): bool
    {
        return $this->payload()['failOnTimeout'] ?? false;
    }

    /**
     * The number of seconds to wait before retrying a job that encountered an uncaught exception.
     *
     * @return null|int|int[]
     */
    public function backoff(): null|array|int
    {
        return $this->payload()['backoff'] ?? $this->payload()['delay'] ?? null;
    }

    /**
     * Get the number of seconds the job can run.
     */
    public function timeout(): ?int
    {
        return $this->payload()['timeout'] ?? null;
    }

    /**
     * Get the timestamp indicating when the job should timeout.
     */
    public function retryUntil(): ?int
    {
        return $this->payload()['retryUntil'] ?? null;
    }

    /**
     * Get the name of the queued job class.
     */
    public function getName(): string
    {
        return $this->payload()['job'];
    }

    /**
     * Get the resolved name of the queued job class.
     *
     * Resolves the name of "wrapped" jobs such as class-based handlers.
     */
    public function resolveName(): string
    {
        return JobName::resolve($this->getName(), $this->payload());
    }

    /**
     * Get the name of the connection the job belongs to.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Get the service container instance.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}
