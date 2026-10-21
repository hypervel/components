<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Queue as BaseQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Sentry\Features\QueueIntegration;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Sentry\TestCase;
use Override;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

class QueueDataCollectionTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.features' => [
            QueueIntegration::class,
        ],
    ];

    /**
     * Reload the application with the given configuration.
     */
    #[Override]
    protected function resetApplicationWithConfig(array $config): void
    {
        // The payload callbacks are static, so we remove the one registered by the previous application
        BaseQueue::createPayloadUsing(null);

        parent::resetApplicationWithConfig($config);
    }

    public function testArrayPayloadIsCollected(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $transaction = $this->pushJob(QueueDataCollectionTestHandler::class, ['user_id' => 1, 'password' => 'secret']);

        $this->assertSame('{"user_id":1,"password":"[Filtered]"}', $this->getSpanData($transaction, 'queue.publish')['messaging.message.body.data']);
        $this->assertSame('{"user_id":1,"password":"[Filtered]"}', $this->getSpanData($transaction, 'queue.process')['messaging.message.body.data']);
    }

    public function testSerializedJobIsNotCollected(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $transaction = $this->pushJob(new QueueDataCollectionTestJob('secret'));

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.publish'));
        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.process'));
    }

    public function testArrayPayloadIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'queues' => false,
            ],
        ]);

        $transaction = $this->pushJob(QueueDataCollectionTestHandler::class, ['user_id' => 1]);

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.publish'));
        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.process'));
    }

    public function testArrayPayloadIsNotCollectedWithoutDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $transaction = $this->pushJob(QueueDataCollectionTestHandler::class, ['user_id' => 1]);

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.publish'));
        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.process'));
    }

    public function testSyncQueueRecordsTheProcessPayloadWithoutAPublishSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $transaction = $this->startTransaction();

        Queue::connection('sync')->push(QueueDataCollectionTestHandler::class, ['user_id' => 1, 'password' => 'secret']);

        $this->assertSame('{"user_id":1,"password":"[Filtered]"}', $this->getSpanData($transaction, 'queue.process')['messaging.message.body.data']);
        $this->assertNotContains('queue.publish', array_map(
            static fn (Span $span): ?string => $span->getOp(),
            $transaction->getSpanRecorder()->getSpans(),
        ));
    }

    /**
     * Publish and process the job within a transaction.
     *
     * Publish spans are recorded from the JobQueueing and JobQueued events, which the
     * sync queue does not raise, so the job is published before the sync queue runs it.
     */
    private function pushJob(object|string $job, array $data = []): Transaction
    {
        $transaction = $this->startTransaction();

        (new QueueDataCollectionTestQueue)
            ->setContainer($this->app)
            ->setConnectionName('sync')
            ->publishAndExecute($job, $data);

        return $transaction;
    }

    /**
     * Get the data of the first span with the given op.
     */
    private function getSpanData(Transaction $transaction, string $op): array
    {
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === $op) {
                return $span->getData();
            }
        }

        $this->fail("No span with the op {$op} was recorded.");
    }
}

class QueueDataCollectionTestQueue extends SyncQueue
{
    /**
     * Raise the publication events for the job, then execute it like the sync queue.
     */
    public function publishAndExecute(object|string $job, mixed $data): void
    {
        $payload = $this->createPayload($job, 'default', $data);

        $this->raiseJobQueueingEvent('default', $job, $payload, null);
        $this->raiseJobQueuedEvent('default', 'test-id', $job, $payload, null);

        $this->executePayload($payload, 'default');
    }
}

class QueueDataCollectionTestJob implements ShouldQueue
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $apiToken,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
    }
}

class QueueDataCollectionTestHandler
{
    /**
     * Handle the queued job.
     */
    public function fire(Job $job, array $data): void
    {
    }
}
