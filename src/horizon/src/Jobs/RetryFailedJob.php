<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Jobs;

use Hypervel\Contracts\Queue\Factory as Queue;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;

class RetryFailedJob
{
    /**
     * Create a new job instance.
     *
     * @param string $id The job ID
     */
    public function __construct(
        public string $id
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(Queue $queue, JobRepository $jobs): void
    {
        if (is_null($job = $jobs->findFailed($this->id))) {
            return;
        }

        $queue->connection($job->connection)->pushRaw(
            $this->preparePayload($id = (string) Str::uuid(), $job->payload),
            $job->queue
        );

        $jobs->storeRetryReference($this->id, $id);
    }

    /**
     * Prepare the payload for queueing.
     */
    protected function preparePayload(string $id, string $payload): string
    {
        $payload = json_decode($payload, true);

        $retry = [
            'id' => $id,
            'uuid' => $id,
            'attempts' => 0,
            'retry_of' => $this->id,
            'retryUntil' => $this->prepareNewTimeout($payload),
        ];

        // Horizon tracks each retry by its new UUID, but a batch tracks the job by its original ID.
        if (isset($payload['data']['batchId'])) {
            $retry['batchJobId'] = $payload['batchJobId'] ?? $payload['uuid'];
        }

        return json_encode(array_merge($payload, $retry));
    }

    /**
     * Prepare the timeout.
     */
    protected function prepareNewTimeout(array $payload): ?int
    {
        $retryUntil = $payload['retryUntil'] ?? $payload['timeoutAt'] ?? null;

        $pushedAt = $payload['pushedAt'] ?? microtime(true);

        return $retryUntil
                        ? CarbonImmutable::now()->addSeconds((int) ceil($retryUntil - $pushedAt))->getTimestamp()
                        : null;
    }
}
