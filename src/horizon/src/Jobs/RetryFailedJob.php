<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Jobs;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Contracts\Queue\Factory as Queue;
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

        return json_encode(array_merge($payload, [
            'id' => $id,
            'uuid' => $id,
            'attempts' => 0,
            'retry_of' => $this->id,
            'retryUntil' => $this->prepareNewTimeout($payload),
        ]));
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
