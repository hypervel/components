<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Aws\Command;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\ObjectPool\PoolErrorReporter;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use RuntimeException;
use Throwable;

class SqsQueue extends Queue implements QueueContract, ClearableQueue
{
    /**
     * The maximum SQS payload size in bytes (1 MB).
     */
    public const MAX_SQS_PAYLOAD_SIZE = 1048576;

    /**
     * The maximum number of messages allowed per SendMessageBatch request.
     */
    public const MAX_MESSAGES_PER_BATCH = 10;

    /**
     * The cache key prefix for extended SQS payloads.
     *
     * IMPORTANT: Uses Laravel's prefix for cross-framework queue interoperability.
     */
    public const EXTENDED_PAYLOAD_CACHE_PREFIX = 'laravel:sqs-payloads:';

    /**
     * The overflow storage options for large payload offloading.
     */
    protected array $overflowStorage;

    /**
     * Create a new Amazon SQS queue instance.
     *
     * @param SqsClient $sqs the Amazon SQS instance
     * @param string $default the name of the default queue
     * @param string $prefix the queue URL prefix
     * @param string $suffix the queue name suffix
     */
    public function __construct(
        protected SqsClient $sqs,
        protected string $default,
        protected string $prefix = '',
        protected string $suffix = '',
        bool $dispatchAfterCommit = false,
        array $overflowStorage = [],
    ) {
        $this->dispatchAfterCommit = $dispatchAfterCommit;
        $this->overflowStorage = $overflowStorage;
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        $response = $this->sqs->getQueueAttributes([
            'QueueUrl' => $this->getQueue($queue),
            'AttributeNames' => [
                'ApproximateNumberOfMessages',
                'ApproximateNumberOfMessagesDelayed',
                'ApproximateNumberOfMessagesNotVisible',
            ],
        ]);

        $a = $response['Attributes'];

        return (int) $a['ApproximateNumberOfMessages']
            + (int) $a['ApproximateNumberOfMessagesDelayed']
            + (int) $a['ApproximateNumberOfMessagesNotVisible'];
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        $response = $this->sqs->getQueueAttributes([
            'QueueUrl' => $this->getQueue($queue),
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        return (int) ($response['Attributes']['ApproximateNumberOfMessages'] ?? 0);
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        $response = $this->sqs->getQueueAttributes([
            'QueueUrl' => $this->getQueue($queue),
            'AttributeNames' => ['ApproximateNumberOfMessagesDelayed'],
        ]);

        return (int) ($response['Attributes']['ApproximateNumberOfMessagesDelayed'] ?? 0);
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        $response = $this->sqs->getQueueAttributes([
            'QueueUrl' => $this->getQueue($queue),
            'AttributeNames' => ['ApproximateNumberOfMessagesNotVisible'],
        ]);

        return (int) ($response['Attributes']['ApproximateNumberOfMessagesNotVisible'] ?? 0);
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     *
     * Not supported by SQS, returns null.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        // Not supported by SQS...
        return null;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload(
                $job,
                $queue === null || $queue === '' ? $this->default : $queue,
                $data
            ),
            $queue,
            null,
            static function (SqsQueue $owner, string $payload, ?string $queue) use ($job) {
                return $owner->pushRaw($payload, $queue, $owner->getQueueableOptions($job, $queue, $payload));
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        if ($this->willOverflow($payload)) {
            $overflowPayload = $payload;
            [$path, $payload] = $this->prepareOverflowPayload($payload);

            $this->storeOverflowPayload($this->overflowStore(), $path, $overflowPayload);
        }

        // The SDK retries connection failures, so a later error may follow an attempt SQS accepted.
        // Retain the overflow payload on every failure because publication is never provably rejected here.
        return $this->sqs->sendMessage([
            'QueueUrl' => $this->getQueue($queue), 'MessageBody' => $payload, ...$options,
        ])->get('MessageId');
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload(
                $job,
                $queue === null || $queue === '' ? $this->default : $queue,
                $data,
                $delay
            ),
            $queue,
            $delay,
            static function (
                SqsQueue $owner,
                string $payload,
                ?string $queue,
                DateInterval|DateTimeInterface|int $delay
            ) use ($job) {
                return $owner->pushRaw(
                    $payload,
                    $queue,
                    $owner->getQueueableOptions($job, $queue, $payload, $delay),
                );
            }
        );
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        $jobs = array_values($jobs);

        if ($jobs === []) {
            return null;
        }

        $transactions = null;

        if ($this->container->has('db.transactions')) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');
        }

        [$afterCommit, $immediate] = $this->partitionJobsByAfterCommit($jobs, $transactions);

        if ($immediate !== []) {
            $this->sendBatchedMessages($this->prepareBatchMessages($immediate, $data, $queue), $queue);
        }

        if ($afterCommit === []) {
            return null;
        }

        /** @var DatabaseTransactionsManager $transactions */
        $messages = $this->prepareBatchMessages($afterCommit, $data, $queue);

        foreach ($afterCommit as $job) {
            $this->addUniqueJobRollbackCallback($transactions, $job);
            $this->addDebouncedJobRollbackCallback($transactions, $job);
        }

        if ($this->afterCommitDispatcher !== null) {
            $dispatcher = $this->afterCommitDispatcher;

            $transactions->addCallback(
                static fn () => $dispatcher(
                    static function (Queue $owner) use ($messages, $queue): void {
                        /** @var SqsQueue $owner */
                        $owner->sendBatchedMessages($messages, $queue);
                    }
                )
            );

            return null;
        }

        $transactions->addCallback(
            fn () => $this->sendBatchedMessages($messages, $queue)
        );

        return null;
    }

    /**
     * Partition jobs by whether they should be deferred until the active transaction commits.
     *
     * @return array{0: array, 1: array}
     */
    protected function partitionJobsByAfterCommit(
        array $jobs,
        ?DatabaseTransactionsManager $transactions
    ): array {
        if ($transactions === null || $transactions->callbackApplicableTransactions()->isEmpty()) {
            return [[], $jobs];
        }

        return Collection::make($jobs)
            ->partition(fn ($job) => $this->shouldDispatchAfterCommit($job))
            ->map(fn ($partition) => $partition->values()->all())
            ->all();
    }

    /**
     * Create the payload for each of the given jobs.
     *
     * Payloads are created at dispatch time, even for jobs deferred until after the transaction commits.
     *
     * @return array<int, array{job: mixed, delay: mixed, payload: string}>
     */
    protected function prepareBatchMessages(array $jobs, mixed $data, ?string $queue): array
    {
        return Collection::make($jobs)
            ->map(function ($job) use ($data, $queue) {
                $delay = is_object($job) ? ($job->delay ?? null) : null;

                return [
                    'job' => $job,
                    'delay' => $delay,
                    'payload' => $this->createPayload(
                        $job,
                        $queue === null || $queue === '' ? $this->default : $queue,
                        $data,
                        $delay,
                    ),
                ];
            })
            ->all();
    }

    /**
     * Build entries, dispatch chunks, and raise queued events with SQS message IDs.
     *
     * @throws SqsException
     * @throws Throwable
     */
    protected function sendBatchedMessages(array $messages, ?string $queue): void
    {
        $entries = [];
        $overflow = [];

        foreach ($messages as $id => $message) {
            $this->raiseJobQueueingEvent($queue, $message['job'], $message['payload'], $message['delay']);

            $entry = $this->prepareSendMessageBatchEntry($id, $message, $queue);

            if ($this->willOverflow($message['payload'])) {
                [$path, $entry['MessageBody']] = $this->prepareOverflowPayload($message['payload']);
                $overflow[(string) $id] = ['path' => $path, 'payload' => $message['payload']];
            }

            $entries[] = $entry;
        }

        $queueUrl = $this->getQueue($queue);
        $store = $overflow === [] ? null : $this->overflowStore();

        // Dispatch chunks serially so later messages cannot arrive ahead of an unsent failed chunk...
        foreach ($this->chunkBatchEntries($entries) as $chunk) {
            $writtenPaths = [];

            try {
                foreach ($chunk as $entry) {
                    if (! isset($overflow[$entry['Id']])) {
                        continue;
                    }

                    /** @var CacheRepository $store */
                    $overflowPayload = $overflow[$entry['Id']];
                    $this->storeOverflowPayload($store, $overflowPayload['path'], $overflowPayload['payload']);
                    $writtenPaths[] = $overflowPayload['path'];
                }
            } catch (Throwable $exception) {
                /** @var CacheRepository $store */
                $this->cleanupOverflowPayloads($store, $writtenPaths);

                throw $exception;
            }

            // A thrown request is ambiguous for the whole chunk, so retain its pointers.
            // Only explicit per-entry failures below are provably rejected.
            $result = $this->sqs->sendMessageBatch([
                'QueueUrl' => $queueUrl,
                'Entries' => $chunk,
            ]);

            foreach ($result['Successful'] ?? [] as $success) {
                if (! isset($messages[$success['Id']])) {
                    continue;
                }

                $message = $messages[$success['Id']];

                $this->raiseJobQueuedEvent(
                    $queue,
                    $success['MessageId'],
                    $message['job'],
                    $message['payload'],
                    $message['delay'],
                );
            }

            if (empty($result['Failed'])) {
                continue;
            }

            $failure = $result['Failed'][0];
            $exception = new SqsException(
                sprintf(
                    'SQS SendMessageBatch rejected [%d] of [%d] messages. First failure [%s]: %s',
                    count($result['Failed']),
                    count($chunk),
                    $failure['Code'] ?? 'Unknown',
                    $failure['Message'] ?? '',
                ),
                new Command('SendMessageBatch', ['QueueUrl' => $queueUrl, 'Entries' => $chunk]),
                [
                    'code' => $failure['Code'] ?? null,
                    'message' => $failure['Message'] ?? null,
                    'result' => $result,
                ],
            );

            if ($store !== null) {
                $rejectedPaths = [];

                foreach ($result['Failed'] as $rejected) {
                    if (isset($overflow[$rejected['Id']])) {
                        $rejectedPaths[] = $overflow[$rejected['Id']]['path'];
                    }
                }

                $this->cleanupOverflowPayloads($store, $rejectedPaths);
            }

            throw $exception;
        }
    }

    /**
     * Build the SendMessageBatch entry for a prepared message.
     *
     * @param array{job: mixed, delay: mixed, payload: string} $message
     */
    protected function prepareSendMessageBatchEntry(int $id, array $message, ?string $queue): array
    {
        return [
            'Id' => (string) $id,
            'MessageBody' => $message['payload'],
            ...$this->getQueueableOptions(
                $message['job'],
                $queue,
                $message['payload'],
                $message['delay'],
            ),
        ];
    }

    /**
     * Chunk batch entries by SQS count and payload-size limits.
     */
    protected function chunkBatchEntries(array $entries): array
    {
        [$chunks, $currentChunk, $currentBytes] = [[], [], 0];

        foreach ($entries as $entry) {
            $bytes = strlen($entry['MessageBody']);
            $wouldExceedCount = count($currentChunk) >= static::MAX_MESSAGES_PER_BATCH;
            $wouldExceedBytes = $currentBytes + $bytes > static::MAX_SQS_PAYLOAD_SIZE;

            if ($currentChunk !== [] && ($wouldExceedCount || $wouldExceedBytes)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentBytes = 0;
            }

            $currentChunk[] = $entry;
            $currentBytes += $bytes;
        }

        if ($currentChunk !== []) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Determine if the payload should be stored in cache.
     */
    protected function willOverflow(string $payload): bool
    {
        if (! Arr::get($this->overflowStorage, 'enabled', false)) {
            return false;
        }

        return Arr::get($this->overflowStorage, 'always', false)
            || strlen($payload) >= static::MAX_SQS_PAYLOAD_SIZE;
    }

    /**
     * Build the cache path and pointer for an overflow payload.
     *
     * @return array{string, string}
     */
    protected function prepareOverflowPayload(string $payload): array
    {
        $decoded = json_decode($payload);
        $uuid = is_object($decoded)
            && isset($decoded->uuid)
            && is_string($decoded->uuid)
            && $decoded->uuid !== ''
            ? $decoded->uuid
            : (string) Str::uuid();
        $path = static::EXTENDED_PAYLOAD_CACHE_PREFIX . $uuid;

        return [$path, json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR)];
    }

    /**
     * Store an overflow payload.
     */
    protected function storeOverflowPayload(CacheRepository $store, string $path, string $payload): void
    {
        if (! $store->put($path, $payload)) {
            throw new RuntimeException("Unable to store the SQS overflow payload [{$path}].");
        }
    }

    /**
     * Remove unpublished overflow payloads without hiding the primary failure.
     *
     * @param list<string> $paths
     */
    protected function cleanupOverflowPayloads(CacheRepository $store, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! $store->forget($path)) {
                    throw new RuntimeException("Unable to delete the SQS overflow payload [{$path}].");
                }
            } catch (Throwable $exception) {
                PoolErrorReporter::report($exception);
            }
        }
    }

    /**
     * Get the cache store for overflow payloads.
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
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?JobContract
    {
        $response = $this->sqs->receiveMessage([
            'QueueUrl' => $queue = $this->getQueue($queue),
            'AttributeNames' => ['ApproximateReceiveCount'],
        ]);

        if (! is_null($response['Messages']) && count($response['Messages']) > 0) {
            return new SqsJob(
                $this->container,
                $this->sqs,
                $response['Messages'][0],
                $this->connectionName,
                $queue,
                $this->overflowStorage,
            );
        }

        return null;
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(?string $queue): int
    {
        return tap($this->size($queue), function () use ($queue) {
            $this->sqs->purgeQueue([
                'QueueUrl' => $this->getQueue($queue),
            ]);

            if (Arr::get($this->overflowStorage, 'enabled')
                && Arr::get($this->overflowStorage, 'flush_on_clear')
                && ! $this->overflowStore()->getStore()->flush()) {
                throw new RuntimeException('Unable to clear the SQS overflow payload store.');
            }
        });
    }

    /**
     * Get the queueable options from the job.
     *
     * @return array{DelaySeconds?: int, MessageGroupId?: string, MessageDeduplicationId?: string}
     */
    public function getQueueableOptions(object|string $job, ?string $queue, string $payload, DateInterval|DateTimeInterface|int|null $delay = null): array
    {
        // Make sure we have a queue name to properly determine if it's a FIFO queue...
        $queue = $queue === null || $queue === '' ? $this->default : $queue;

        $isObject = is_object($job);
        $isFifo = str_ends_with((string) $queue, '.fifo');

        $options = [];

        // DelaySeconds cannot be used with FIFO queues. AWS will return an error...
        if (! empty($delay) && ! $isFifo) {
            $options['DelaySeconds'] = $this->secondsUntil($delay);
        }

        // If the job is a string job on a standard queue, there are no more options...
        if (! $isObject && ! $isFifo) {
            return $options;
        }

        $transformToString = fn ($value) => (string) $value;

        // The message group ID is required for FIFO queues and is optional for
        // standard queues. Job objects contain a group ID. With string jobs
        // sent to FIFO queues, assign these to the same message group ID.
        $messageGroupId = null;

        if ($isObject) {
            $messageGroupId = transform($job->messageGroup ?? (method_exists($job, 'messageGroup') ? $job->messageGroup() : null), $transformToString);
        } elseif ($isFifo) {
            $messageGroupId = transform($queue, $transformToString);
        }

        $options['MessageGroupId'] = $messageGroupId;

        // The message deduplication ID is only valid for FIFO queues. Every job
        // without the method will be considered unique. To use content-based
        // deduplication enable it in AWS and have the method return empty.
        $messageDeduplicationId = null;

        if ($isFifo) {
            $messageDeduplicationId = match (true) {
                $isObject && isset($job->deduplicator) && is_callable($job->deduplicator) => transform(call_user_func($job->deduplicator, $payload, $queue), $transformToString),
                $isObject && method_exists($job, 'deduplicationId') => transform($job->deduplicationId($payload, $queue), $transformToString),
                default => (string) Str::orderedUuid(),
            };
        }

        $options['MessageDeduplicationId'] = $messageDeduplicationId;

        return array_filter($options, static fn ($value) => $value !== null);
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        $queue = $queue === null || $queue === '' ? $this->default : $queue;

        return filter_var($queue, FILTER_VALIDATE_URL) === false
            ? $this->suffixQueue($queue, $this->suffix)
            : $queue;
    }

    /**
     * Add the given suffix to the given queue name.
     */
    protected function suffixQueue(string $queue, string $suffix = ''): string
    {
        if (str_ends_with($queue, '.fifo')) {
            $queue = Str::beforeLast($queue, '.fifo');

            return rtrim($this->prefix, '/') . '/' . Str::finish($queue, $suffix) . '.fifo';
        }

        return rtrim($this->prefix, '/') . '/' . Str::finish($queue, $this->suffix);
    }

    /**
     * Get the underlying SQS instance.
     */
    public function getSqs(): SqsClient
    {
        return $this->sqs;
    }
}
