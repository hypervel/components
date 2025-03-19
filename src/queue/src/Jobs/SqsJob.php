<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Aws\Sqs\SqsClient;
use Psr\Container\ContainerInterface;

class SqsJob extends Job
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected SqsClient $sqs,
        protected array $job,
        protected string $connectionName,
        protected ?string $queue
    ) {
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $this->sqs->changeMessageVisibility([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => $this->job['ReceiptHandle'],
            'VisibilityTimeout' => $delay,
        ]);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->sqs->deleteMessage([
            'QueueUrl' => $this->queue,
            'ReceiptHandle' => $this->job['ReceiptHandle'],
        ]);
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
        return $this->job['Body'];
    }

    /**
     * Get the underlying SQS client instance.
     */
    public function getSqs(): SqsClient
    {
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
