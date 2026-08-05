<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\ManuallyFailedException;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Support\InteractsWithTime;
use JsonException;
use RuntimeException;
use Throwable;

abstract class Job implements JobContract
{
    use InteractsWithTime;

    /**
     * The job handler instance.
     */
    protected mixed $instance;

    /**
     * The validated job payload.
     */
    protected ?array $decodedPayload = null;

    /**
     * The exception raised while validating the job payload.
     */
    protected ?InvalidPayloadException $payloadException = null;

    /**
     * The IoC container instance.
     */
    protected Container $container;

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
    protected string $queue;

    /**
     * The lease pinning this job's pooled backend until a terminal operation.
     */
    protected ?Lease $poolLease = null;

    /**
     * Indicates whether this job has ever carried a pool lease.
     */
    protected bool $poolLeaseAttached = false;

    /**
     * Get the job identifier.
     */
    abstract public function getJobId(): int|string|null;

    /**
     * Get the raw body of the job.
     */
    abstract public function getRawBody(): string;

    /**
     * Attach the backend lease held for this job.
     */
    public function withPoolLease(Lease $lease): static
    {
        if ($this->poolLeaseAttached) {
            throw new RuntimeException('A queue job cannot be attached to more than one pool lease.');
        }

        // Attach first so a failing initialization hook can still recover the
        // reserved job through its normal terminal operation.
        $this->poolLeaseAttached = true;
        $this->poolLease = $lease;
        $this->onPoolLeaseAttached();

        return $this;
    }

    /**
     * Initialize state that must be captured while the backend is pinned.
     */
    protected function onPoolLeaseAttached(): void
    {
    }

    /**
     * Return the pinned backend to its pool.
     */
    protected function releasePoolLease(): void
    {
        $lease = $this->poolLease;
        $this->poolLease = null;

        $lease?->release();
    }

    /**
     * Discard the pinned backend instead of returning it to its pool.
     */
    protected function discardPoolLease(): void
    {
        $lease = $this->poolLease;
        $this->poolLease = null;

        $lease?->discard();
    }

    /**
     * Determine whether a previously attached backend lease is finalized.
     */
    protected function poolLeaseIsFinalized(): bool
    {
        return $this->poolLeaseAttached && $this->poolLease === null;
    }

    /**
     * Discard a backend after failure without masking the primary exception.
     */
    protected function discardPoolLeaseAfterFailure(Throwable $exception): never
    {
        try {
            $this->discardPoolLease();
        } catch (Throwable $cleanupException) {
            PoolErrorReporter::report($cleanupException);
        }

        throw $exception;
    }

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

        try {
            $commandName = $this->payload()['data']['commandName'] ?? false;
        } catch (InvalidPayloadException) {
            $commandName = false;
        }

        // If the exception is due to a job timing out, we need to rollback the current
        // database transaction so that the failed job count can be incremented with
        // the proper value. Otherwise, the current transaction will never commit.
        if ($e instanceof TimeoutExceededException
            && $commandName
            && isset(class_uses_recursive($commandName)[Batchable::class])
        ) {
            $batchRepository = $this->resolve(BatchRepository::class);

            try {
                $batchRepository->rollBack();
            } catch (Throwable) {
                // ...
            }
        }

        if ($this->shouldRollBackDatabaseTransaction($e)) {
            $config = $this->container->make('config');

            $this->container->make('db')
                ->connection($config->string('queue.failed.database'))
                ->rollBack(toLevel: 0);
        }

        try {
            // If the job has failed, we will delete it, call the "failed" method and then call
            // an event indicating the job has failed so it can be logged if needed. This is
            // to allow every developer to better keep monitor of their failed queue jobs.
            $this->delete();

            if ($this->payloadException === null) {
                $this->failed($e);
            }
        } finally {
            $this->resolve(Dispatcher::class)
                ->dispatch(new JobFailed(
                    $this->connectionName,
                    $this,
                    $e ?: new ManuallyFailedException
                ));
        }
    }

    /**
     * Determine if the current database transaction should be rolled back to level zero.
     */
    protected function shouldRollBackDatabaseTransaction(?Throwable $e): bool
    {
        if (! $e instanceof TimeoutExceededException) {
            return false;
        }

        $config = $this->container->make('config');

        return $config->get('queue.failed.database')
            && in_array($config->get('queue.failed.driver'), ['database', 'database-uuids'], true)
            && $this->container->bound('db');
    }

    /**
     * Process an exception that caused the job to fail.
     */
    protected function failed(?Throwable $e): void
    {
        $payload = $this->payload();

        [$class, $method] = JobName::parse($payload['job']);

        if (method_exists($this->instance = $this->resolve($class), 'failed')) {
            $this->instance->failed($payload['data'], $e, $payload['uuid'] ?? '', $this);
        }
    }

    /**
     * Resolve the given class.
     */
    protected function resolve(string $class): mixed
    {
        return $this->container->make($class);
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
        if ($this->decodedPayload !== null) {
            return $this->decodedPayload;
        }

        if ($this->payloadException !== null) {
            throw $this->payloadException;
        }

        $rawBody = null;

        try {
            $rawBody = $this->getRawBody();
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)
                || ! isset($payload['job'])
                || ! is_string($payload['job'])
                || $payload['job'] === ''
                || ! array_key_exists('data', $payload)) {
                throw new InvalidPayloadException(
                    'The queue job payload does not contain a valid job and data.',
                    $rawBody,
                );
            }

            return $this->decodedPayload = $payload;
        } catch (InvalidPayloadException $e) {
            throw $this->payloadException = $e;
        } catch (JsonException $e) {
            throw $this->payloadException = new InvalidPayloadException(
                'Unable to decode the queue job payload: ' . $e->getMessage(),
                $rawBody,
            );
        }
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
     * @return null|int|int[]|string
     */
    public function backoff(): array|int|string|null
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
     * Get the class of the queued job.
     *
     * Resolves the class of "wrapped" jobs such as class-based handlers.
     */
    public function resolveQueuedJobClass(): string
    {
        return JobName::resolveClassName($this->getName(), $this->payload());
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
    public function getContainer(): Container
    {
        return $this->container;
    }
}
