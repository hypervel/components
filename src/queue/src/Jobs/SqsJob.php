<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Aws\Sqs\SqsClient;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\Support\Arr;
use RuntimeException;
use Throwable;

class SqsJob extends Job
{
    /**
     * The cached raw body of the job.
     */
    protected ?string $cachedRawBody = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Container $container,
        protected SqsClient $sqs,
        protected array $job,
        protected string $connectionName,
        protected string $queue,
        protected array $overflowStorage = [],
    ) {
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);

        try {
            $this->getSqs()->changeMessageVisibility([
                'QueueUrl' => $this->queue,
                'ReceiptHandle' => $this->job['ReceiptHandle'],
                'VisibilityTimeout' => $delay,
            ]);
        } catch (Throwable $exception) {
            $this->discardPoolLeaseAfterFailure($exception);
        }

        $this->releasePoolLease();
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        try {
            $this->deleteMessageFromSqs();
        } catch (Throwable $exception) {
            $this->discardPoolLeaseAfterFailure($exception);
        }

        $releaseException = null;

        try {
            $this->releasePoolLease();
        } catch (Throwable $exception) {
            $releaseException = $exception;
        }

        try {
            $this->deleteOverflowPayload();
        } catch (Throwable $cleanupException) {
            if ($releaseException !== null) {
                PoolErrorReporter::report($cleanupException);

                throw $releaseException;
            }

            throw $cleanupException;
        }

        if ($releaseException !== null) {
            throw $releaseException;
        }
    }

    /**
     * Delete the message from the SQS queue.
     */
    protected function deleteMessageFromSqs(): void
    {
        $this->getSqs()->deleteMessage([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => $this->job['ReceiptHandle'],
        ]);
    }

    /**
     * Delete the offloaded overflow payload from the cache, if applicable.
     */
    protected function deleteOverflowPayload(): void
    {
        if (! Arr::get($this->overflowStorage, 'delete_after_processing')
            || ($pointer = $this->overflowPointer()) === null) {
            return;
        }

        if (! $this->overflowStore()->forget($pointer)) {
            throw new RuntimeException("Unable to delete the SQS overflow payload [{$pointer}].");
        }
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return (int) $this->job['Attributes']['ApproximateReceiveCount'];
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return $this->job['MessageId'];
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        if ($this->cachedRawBody !== null) {
            return $this->cachedRawBody;
        }

        $body = $this->job['Body'];

        if (($pointer = $this->overflowPointer()) !== null) {
            $payload = $this->overflowStore()->get($pointer);

            if (is_string($payload)) {
                $body = $payload;
            }
        }

        return $this->cachedRawBody = $body;
    }

    /**
     * Resolve the pointer path from the job body, if present.
     */
    protected function overflowPointer(): ?string
    {
        if (! Arr::get($this->overflowStorage, 'enabled', false)) {
            return null;
        }

        $body = $this->job['Body'] ?? null;

        if (! is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) && is_string($decoded['@pointer'] ?? null)
            ? $decoded['@pointer']
            : null;
    }

    /**
     * Resolve the configured cache store for overflow payloads.
     */
    protected function overflowStore(): CacheRepository
    {
        /** @var CacheFactory $cache */
        $cache = $this->container->make('cache');
        /** @var ?string $store */
        $store = Arr::get($this->overflowStorage, 'store');

        return $cache->store($store);
    }

    /**
     * Get the underlying SQS client instance.
     */
    public function getSqs(): SqsClient
    {
        if ($this->poolLeaseIsFinalized()) {
            throw new RuntimeException('The pooled SQS job client is no longer available after a terminal operation.');
        }

        return $this->sqs;
    }

    /**
     * Get the underlying raw SQS job.
     */
    public function getSqsJob(): array
    {
        return $this->job;
    }
}
