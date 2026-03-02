<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Aws\Sqs\SqsClient;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Support\Str;

class SqsQueue extends Queue implements QueueContract, ClearableQueue
{
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
        protected ?bool $dispatchAfterCommit = false
    ) {
        $this->sqs = $sqs;
        $this->prefix = $prefix;
        $this->default = $default;
        $this->suffix = $suffix;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
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
            $this->createPayload($job, $queue ?: $this->default, $data),
            $queue,
            null,
            function ($payload, $queue) use ($job) {
                return $this->pushRaw($payload, $queue, $this->getQueueableOptions($job, $queue, $payload));
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
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
            $this->createPayload($job, $queue ?: $this->default, $data, $delay),
            $queue,
            $delay,
            function ($payload, $queue, $delay) use ($job) {
                return $this->pushRaw($payload, $queue, $this->getQueueableOptions($job, $queue, $payload, $delay));
            }
        );
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        foreach ((array) $jobs as $job) {
            if (isset($job->delay)) {
                $this->later($job->delay, $job, $data, $queue);
            } else {
                $this->push($job, $data, $queue);
            }
        }

        return null;
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
                $queue
            );
        }

        return null;
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(string $queue): int
    {
        return tap($this->size($queue), function () use ($queue) {
            $this->sqs->purgeQueue([
                'QueueUrl' => $this->getQueue($queue),
            ]);
        });
    }

    /**
     * Get the queueable options from the job.
     *
     * @return array{DelaySeconds?: int, MessageGroupId?: string, MessageDeduplicationId?: string}
     */
    protected function getQueueableOptions(object|string $job, ?string $queue, string $payload, DateInterval|DateTimeInterface|int|null $delay = null): array
    {
        // Make sure we have a queue name to properly determine if it's a FIFO queue...
        $queue ??= $this->default;

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

        return array_filter($options);
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        $queue = $queue ?: $this->default;

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
