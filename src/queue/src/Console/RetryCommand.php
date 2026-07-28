<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use __PHP_Incomplete_Class;
use DateTimeInterface;
use Hypervel\Console\Command;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\Events\JobRetryRequested;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SqsQueue;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:retry')]
class RetryCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'queue:retry
                            {id?* : The ID of the failed job or "all" to retry all jobs}
                            {--queue= : Retry all of the failed jobs for the specified queue}
                            {--range=* : Range of job IDs (numeric) to be retried (e.g. 1-5)}';

    /**
     * The console command description.
     */
    protected string $description = 'Retry a failed queue job';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $jobsFound = count($ids = $this->getJobIds()) > 0;

        if ($jobsFound) {
            $this->components->info('Pushing failed queue jobs back onto the queue.');
        }

        /** @var FailedJobProviderInterface $failer */
        $failer = $this->hypervel->make('queue.failer');

        foreach ($ids as $id) {
            $job = $failer->find($id);

            if (is_null($job)) {
                $this->components->error("Unable to find failed job with ID [{$id}].");
            } else {
                $this->hypervel['events']->dispatch(new JobRetryRequested($job));

                $this->components->task($id, fn () => $this->retryJob($job));

                $failer->forget($id);
            }
        }

        $jobsFound ? $this->newLine() : $this->components->info('No retryable jobs found.');
    }

    /**
     * Get the job IDs to be retried.
     */
    protected function getJobIds(): array
    {
        $ids = (array) $this->argument('id');

        if (count($ids) === 1 && $ids[0] === 'all') {
            /** @var FailedJobProviderInterface $failer */
            $failer = $this->hypervel->make('queue.failer');

            return $failer->ids();
        }

        if ($queue = $this->option('queue')) {
            return $this->getJobIdsByQueue($queue);
        }

        if ($ranges = (array) $this->option('range')) {
            $ids = array_merge($ids, $this->getJobIdsByRanges($ranges));
        }

        return array_values(array_filter(array_unique($ids)));
    }

    /**
     * Get the job IDs by queue, if applicable.
     */
    protected function getJobIdsByQueue(string $queue): array
    {
        /** @var FailedJobProviderInterface $failer */
        $failer = $this->hypervel->make('queue.failer');

        $ids = $failer->ids($queue);

        if (count($ids) === 0) {
            $this->components->error("Unable to find failed jobs for queue [{$queue}].");
        }

        return $ids;
    }

    /**
     * Get the job IDs ranges, if applicable.
     */
    protected function getJobIdsByRanges(array $ranges): array
    {
        $ids = [];

        foreach ($ranges as $range) {
            if (preg_match('/^[0-9]+\-[0-9]+$/', $range)) {
                $ids = array_merge($ids, range(...explode('-', $range)));
            }
        }

        return $ids;
    }

    /**
     * Retry the queue job.
     */
    protected function retryJob(stdClass $job): void
    {
        $queue = $this->hypervel['queue']->connection($job->connection);

        $queue->pushRaw(
            $this->refreshRetryUntil($this->resetAttempts($job->payload)),
            $job->queue,
            $this->getQueueableOptions($queue, $job),
        );
    }

    /**
     * Reset the payload attempts.
     *
     * Applicable to Redis and other jobs which store attempts in their payload.
     */
    protected function resetAttempts(string $payload): string
    {
        $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (isset($payload['attempts'])) {
            $payload['attempts'] = 0;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Refresh the "retry until" timestamp for the job.
     *
     * @throws RuntimeException
     */
    protected function refreshRetryUntil(string $payload): string
    {
        $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (! isset($payload['data']['command'])) {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        }

        $instance = $this->getInstanceFromPayload($payload);

        if (is_object($instance) && ! $instance instanceof __PHP_Incomplete_Class && method_exists($instance, 'retryUntil')) {
            $retryUntil = $instance->retryUntil();

            $payload['retryUntil'] = $retryUntil instanceof DateTimeInterface
                ? $retryUntil->getTimestamp()
                : $retryUntil;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the queueable options from the job.
     */
    protected function getQueueableOptions(Queue $queue, stdClass $job): array
    {
        if (! $queue instanceof SqsQueue
            && (! $queue instanceof QueuePoolProxy || $queue->getDefinition()->resourceType !== 'sqs')
        ) {
            return [];
        }

        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);

        if (! isset($payload['data']['command'])) {
            return [];
        }

        $getOptions = fn (SqsQueue $queue) => $queue->getQueueableOptions(
            $this->getInstanceFromPayload($payload),
            $job->queue,
            $job->payload,
        );

        return $queue instanceof QueuePoolProxy
            ? $queue->withConnection($getOptions)
            : $getOptions($queue);
    }

    /**
     * Get the job instance from the given payload.
     *
     * @throws RuntimeException
     */
    protected function getInstanceFromPayload(array $payload): mixed
    {
        if (str_starts_with($payload['data']['command'], 'O:')) {
            return unserialize($payload['data']['command']);
        }

        if ($this->hypervel->has(Encrypter::class)) {
            return unserialize($this->hypervel->make(Encrypter::class)->decrypt($payload['data']['command']));
        }

        throw new RuntimeException('Unable to extract job payload.');
    }
}
