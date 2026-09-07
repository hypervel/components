<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Queue\SyncQueue;
use Hypervel\Sentry\Features\QueueFeature;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\SentryTestCase;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\EventType;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

use function Hypervel\Coroutine\wait;
use function Sentry\addBreadcrumb;
use function Sentry\captureException;

class QueueIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.features' => [
            QueueFeature::class,
        ],
    ];

    protected function withTracingEnabled(ApplicationContract $app): void
    {
        $app->make('config')->set('sentry.traces_sample_rate', 1.0);
    }

    protected function withQueueJobTracingDisabled(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('sentry.traces_sample_rate', 1.0);
        $config->set('sentry.tracing.queue_job_transactions', false);
    }

    protected function withLocalQueueOutputDisabled(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('sentry.traces_sample_rate', null);
        $config->set('sentry.breadcrumbs.queue_info', false);
        $config->set('sentry.tracing.queue_jobs', false);
        $config->set('sentry.tracing.queue_job_transactions', false);
    }

    public function testQueueJobPushesAndPopsScopeWithBreadcrumbs(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    public function testQueueJobThatReportsPushesAndPopsScopeWithBreadcrumbs(): void
    {
        dispatch(new QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());

        $this->assertNotNull($this->getLastSentryEvent());

        $event = $this->getLastSentryEvent();

        $this->assertCount(2, $event->getBreadcrumbs());
    }

    public function testQueueJobThatThrowsLeavesPushedScopeWithBreadcrumbs(): void
    {
        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        // We still expect to find the breadcrumbs from the job here so they are attached to reported exceptions

        $this->assertCount(2, $this->getCurrentSentryBreadcrumbs());

        $firstBreadcrumb = $this->getCurrentSentryBreadcrumbs()[0];
        $this->assertEquals('queue.job', $firstBreadcrumb->getCategory());

        $secondBreadcrumb = $this->getCurrentSentryBreadcrumbs()[1];
        $this->assertEquals('test', $secondBreadcrumb->getCategory());
    }

    public function testQueueJobsThatThrowPopsAndPushesScopeWithBreadcrumbsBeforeNewJob(): void
    {
        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('test #1'));
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('test #2'));
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        // We only expect to find the breadcrumbs from the second job here

        $this->assertCount(2, $this->getCurrentSentryBreadcrumbs());

        $firstBreadcrumb = $this->getCurrentSentryBreadcrumbs()[0];
        $this->assertEquals('queue.job', $firstBreadcrumb->getCategory());

        $secondBreadcrumb = $this->getCurrentSentryBreadcrumbs()[1];
        $this->assertEquals('test #2', $secondBreadcrumb->getMessage());
    }

    public function testQueueJobsWithBreadcrumbSetInBetweenKeepsNonJobBreadcrumbsOnCurrentScope(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::LEVEL_DEBUG, 'test2', 'test2'));

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
    }

    #[DefineEnvironment('withLocalQueueOutputDisabled')]
    public function testPropagationAndLifecycleFlushRemainWithoutLocalQueueOutput(): void
    {
        $dispatcher = $this->app->make('events');
        $queue = (new QueueFeatureTestQueue)->setConnectionName('sync');
        $payload = json_decode(
            $queue->createPayloadForTest(new QueueEventsTestJob, 'default'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertFalse($this->hasQueueFeatureListener(JobQueueing::class));
        $this->assertFalse($this->hasQueueFeatureListener(JobProcessing::class));
        $this->assertTrue($this->hasQueueFeatureListener(JobExceptionOccurred::class));
        $this->assertTrue($this->hasQueueFeatureListener(WorkerStopping::class));
        $this->assertArrayHasKey('sentry_baggage_data', $payload);
        $this->assertArrayHasKey('sentry_trace_parent_data', $payload);
        $this->assertIsFloat($payload['sentry_publish_time']);
        $this->assertArrayNotHasKey('sentry_destination_name', $payload);
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testQueueJobCreatesTransactionByDefault(): void
    {
        dispatch(new QueueEventsTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $this->assertEquals(EventType::transaction(), $transaction->getType());
        $this->assertEquals(QueueEventsTestJob::class, $transaction->getTransaction());

        $traceContext = $transaction->getContexts()['trace'];

        $this->assertEquals('queue.process', $traceContext['op']);
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testDefaultQueuePublishAndProcessSpansUseResolvedDestination(): void
    {
        $transaction = $this->startTransaction();
        $queue = (new QueueFeatureTestQueue)
            ->setContainer($this->app)
            ->setConnectionName('test');
        $payload = $queue->enqueueForTest(new QueueEventsTestJob);
        $job = new QueueFeatureResolvedQueueJob($this->app, $payload, 'test', 'default');

        $this->dispatchHypervelEvent(new JobProcessing('test', $job));
        $this->dispatchHypervelEvent(new JobProcessed('test', $job));

        $destinations = [];

        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if (in_array($span->getOp(), ['queue.publish', 'queue.process'], true)) {
                $destinations[$span->getOp()] = $span->getData()['messaging.destination.name'];
            }
        }

        $this->assertSame([
            'queue.publish' => 'default',
            'queue.process' => 'default',
        ], $destinations);
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testPublishSpansMatchMixedOutOfOrderTerminalsByPayload(): void
    {
        $transaction = $this->startTransaction();
        $payloads = [
            'A' => json_encode(['uuid' => 'a'], JSON_THROW_ON_ERROR),
            'B' => json_encode(['uuid' => 'b'], JSON_THROW_ON_ERROR),
            'C' => json_encode(['uuid' => 'c'], JSON_THROW_ON_ERROR),
        ];

        foreach ($payloads as $job => $payload) {
            $this->dispatchHypervelEvent(new JobQueueing('test', 'default', $job, $payload, null));
            $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
        }

        $this->dispatchHypervelEvent(new JobQueued('test', 'default', 'b-id', 'B', $payloads['B'], null));
        $this->dispatchHypervelEvent(new JobQueueingFailed(
            'test',
            'default',
            'A',
            $payloads['A'],
            null,
            new RuntimeException('The queue rejected job A.'),
        ));
        $this->dispatchHypervelEvent(new JobQueued('test', 'default', 'c-id', 'C', $payloads['C'], null));

        $spans = [];

        foreach (array_slice($transaction->getSpanRecorder()->getSpans(), 1) as $span) {
            $spans[$span->getDescription()] = $span;
        }

        $this->assertSame(SpanStatus::internalError(), $spans['A']->getStatus());
        $this->assertSame(SpanStatus::ok(), $spans['B']->getStatus());
        $this->assertSame(SpanStatus::ok(), $spans['C']->getStatus());
        $this->assertNotNull($spans['A']->getEndTimestamp());
        $this->assertNotNull($spans['B']->getEndTimestamp());
        $this->assertNotNull($spans['C']->getEndTimestamp());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testUnterminatedPublishSpanIsFinishedAtCoroutineExit(): void
    {
        $span = wait(function (): Span {
            $transaction = $this->startTransaction();
            $payload = json_encode(['uuid' => 'orphaned'], JSON_THROW_ON_ERROR);

            $this->dispatchHypervelEvent(new JobQueueing('test', 'default', 'Orphaned', $payload, null));

            return $transaction->getSpanRecorder()->getSpans()[1];
        });

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
    }

    #[DefineEnvironment('withQueueJobTracingDisabled')]
    public function testQueueJobDoesntCreateTransaction(): void
    {
        dispatch(new QueueEventsTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNull($transaction);
    }

    /**
     * Determine if the event has a Queue feature listener.
     */
    private function hasQueueFeatureListener(string $event): bool
    {
        $listeners = $this->app->make('events')->getRawListeners()[$event] ?? [];

        foreach ($listeners as $listener) {
            if (is_array($listener) && ($listener[0] ?? null) instanceof QueueFeature) {
                return true;
            }
        }

        return false;
    }
}

class QueueEventsTestJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}

class QueueFeatureTestQueue extends SyncQueue
{
    /**
     * Enqueue a job through the publication lifecycle for testing.
     */
    public function enqueueForTest(object|string $job, ?string $queue = null): string
    {
        $resolvedQueue = $queue ?? 'default';
        $payload = $this->createPayload($job, $resolvedQueue);

        $this->raiseJobQueueingEvent($queue, $job, $payload, null);
        $this->raiseJobQueuedEvent($queue, 'test-id', $job, $payload, null);

        return $payload;
    }

    /**
     * Create a queue payload for testing.
     */
    public function createPayloadForTest(array|object|string $job, ?string $queue): string
    {
        return $this->createPayload($job, $queue);
    }
}

class QueueFeatureResolvedQueueJob extends SyncJob
{
    /**
     * Get the resolved queue name.
     */
    public function getQueue(): string
    {
        return $this->queue;
    }
}

function queueEventsTestAddTestBreadcrumb($message = null): void
{
    addBreadcrumb(
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::LEVEL_DEBUG,
            'test',
            $message ?? 'test'
        )
    );
}

class QueueEventsTestJobWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();
    }
}

class QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();

        captureException(new Exception('This is a test exception'));
    }
}

class QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb implements ShouldQueue
{
    public function __construct(
        private ?string $message = null,
    ) {
    }

    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb($this->message);

        throw new Exception('This is a test exception');
    }
}
