<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Arr;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\Stringable\Str;
use Hypervel\Bus\Contracts\BatchRepository;
use Hypervel\Encryption\Contracts\Encrypter;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Queue;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\EntryUpdate;
use Hypervel\Telescope\ExceptionContext;
use Hypervel\Telescope\ExtractProperties;
use Hypervel\Telescope\ExtractTags;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

class JobWatcher extends Watcher
{
    /**
     * The list of ignored jobs classes.
     *
     * @var array<int, class-string>
     */
    protected $ignoredJobClasses = [
        \Hypervel\Telescope\Jobs\ProcessPendingUpdates::class,
    ];

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['telescope_uuid' => optional($this->recordJob($connection, $queue, $payload))->uuid];
        });

        $app->get(EventDispatcherInterface::class)
            ->listen(JobProcessed::class, [$this, 'recordProcessedJob']);
        $app->get(EventDispatcherInterface::class)
            ->listen(JobFailed::class, [$this, 'recordFailedJob']);
    }

    /**
     * Record a job being created.
     */
    public function recordJob(string $connection, ?string $queue, array $payload): ?IncomingEntry
    {
        if (! Telescope::isRecording()) {
            return null;
        }

        $job = isset($payload['data']['command'])
            ? get_class($payload['data']['command'])
            : $payload['job'];

        if (in_array($job, $this->ignoredJobClasses)) {
            return null;
        }

        $content = array_merge([
            'status' => 'pending',
        ], $this->defaultJobData($connection, $queue, $payload, $this->data($payload)));

        Telescope::recordJob(
            $entry = IncomingEntry::make($content)
                ->withFamilyHash($content['data']['batchId'] ?? null)
                ->tags($this->tags($payload))
        );

        return $entry;
    }

    /**
     * Record a queued job was processed.
     */
    public function recordProcessedJob(JobProcessed $event): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        /* @phpstan-ignore-next-line */
        $uuid = $event->job->payload()['telescope_uuid'] ?? null;

        if (! $uuid) {
            return;
        }

        Telescope::recordUpdate(EntryUpdate::make(
            $uuid,
            EntryType::JOB,
            ['status' => 'processed']
        ));

        /* @phpstan-ignore-next-line */
        $this->updateBatch($event->job->payload());
    }

    /**
     * Record a queue job has failed.
     */
    public function recordFailedJob(JobFailed $event): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        /* @phpstan-ignore-next-line */
        $uuid = $event->job->payload()['telescope_uuid'] ?? null;

        if (! $uuid) {
            return;
        }

        Telescope::recordUpdate(EntryUpdate::make(
            $uuid,
            EntryType::JOB,
            [
                'status' => 'failed',
                'exception' => [
                    'message' => $event->exception->getMessage(),
                    'trace' => collect($event->exception->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
                    'line' => $event->exception->getLine(),
                    'line_preview' => ExceptionContext::get($event->exception),
                ],
            ]
        )->addTags(['failed']));

        /* @phpstan-ignore-next-line */
        $this->updateBatch($event->job->payload());
    }

    /**
     * Get the default entry data for the given job.
     */
    protected function defaultJobData(string $connection, ?string $queue, array $payload, array $data): array
    {
        return [
            'connection' => $connection,
            'queue' => $queue ?: 'default',
            'name' => $payload['displayName'],
            'tries' => $payload['maxTries'],
            'timeout' => $payload['timeout'],
            'data' => $data,
        ];
    }

    /**
     * Extract the job "data" from the job payload.
     */
    protected function data(array $payload): array
    {
        if (! isset($payload['data']['command'])) {
            return $payload['data'];
        }

        return ExtractProperties::from(
            $payload['data']['command']
        );
    }

    /**
     * Extract the tags from the job payload.
     */
    protected function tags(array $payload): array
    {
        if (! isset($payload['data']['command'])) {
            return [];
        }

        return ExtractTags::fromJob(
            $payload['data']['command']
        );
    }

    /**
     * Update the batch.
     */
    protected function updateBatch(array $payload): void
    {
        if (! isset($payload['data']['command'])) {
            return;
        }

        $wasRecordingEnabled = Telescope::isRecording();

        Telescope::stopRecording();

        $batchId = $this->getBatchId($payload['data']);

        if ($wasRecordingEnabled) {
            Telescope::startRecording();
        }

        if (! is_null($batchId)) {
            $batch = app(BatchRepository::class)->find($batchId);

            if (is_null($batch)) {
                return;
            }

            Telescope::recordUpdate(EntryUpdate::make(
                $batchId,
                EntryType::BATCH,
                $batch->toArray()
            ));
        }
    }

    /**
     * Get the command from the given payload.
     *
     * @throws RuntimeException
     */
    protected function getCommand(array $data): mixed
    {
        if (Str::startsWith($data['command'], 'O:')) {
            return unserialize($data['command']);
        }

        if (app()->has(Encrypter::class)) {
            return unserialize(app(Encrypter::class)->decrypt($data['command']));
        }

        throw new RuntimeException('Unable to extract job payload.');
    }

    /**
     * Get the batch ID from the given payload.
     *
     * @throws RuntimeException
     */
    protected function getBatchId(array $data): ?string
    {
        try {
            $command = $this->getCommand($data);

            $properties = ExtractProperties::from($command);

            return $properties['batchId'] ?? null;
        } catch (ModelNotFoundException $e) {
            if (preg_match('/"batchId";s:\d+:"([^"]+)"/', $data['command'], $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
