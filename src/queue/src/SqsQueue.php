<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Aws\Sqs\SqsClient;
use DateInterval;
use DateTimeInterface;
use Hyperf\Stringable\Str;
use Hypervel\Queue\Contracts\ClearableQueue;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Queue\Contracts\Queue as QueueContract;
use Hypervel\Queue\Jobs\SqsJob;

use function Hyperf\Tappable\tap;

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
        protected bool $dispatchAfterCommit = false
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
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        $attributes = $response->get('Attributes');

        return (int) $attributes['ApproximateNumberOfMessages'];
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
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->sqs->sendMessage([
            'QueueUrl' => $this->getQueue($queue),
            'MessageBody' => $payload,
        ])->get('MessageId');
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?: $this->default, $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return $this->sqs->sendMessage([
                    'QueueUrl' => $this->getQueue($queue),
                    'MessageBody' => $payload,
                    'DelaySeconds' => $this->secondsUntil($delay),
                ])->get('MessageId');
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
