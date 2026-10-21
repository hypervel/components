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
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Queue\SyncQueue;
use Hypervel\Sentry\Features\QueueIntegration;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\TestCase;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\EventType;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

use function Hypervel\Coroutine\wait;
use function Sentry\addBreadcrumb;
use function Sentry\captureException;

class QueueIntegrationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.features' => [
            QueueIntegration::class,
        ],
    ];

    /**
     * Enable tracing for all transactions.
     */
    protected function withTracingEnabled(ApplicationContract $app): void
    {
        $app->make('config')->set('sentry.traces_sample_rate', 1.0);
    }

    /**
     * Enable tracing without queue job transactions.
     */
    protected function withQueueJobTracingDisabled(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('sentry.traces_sample_rate', 1.0);
        $config->set('sentry.tracing.queue_job_transactions', false);
    }

    /**
     * Enable tracing with queue job transactions but without queue job spans.
     */
    protected function withQueueJobSpansDisabled(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('sentry.traces_sample_rate', 1.0);
        $config->set('sentry.tracing.queue_jobs', false);
    }

    /**
     * Disable queue breadcrumbs and spans.
     */
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

    #[DefineEnvironment('withTracingEnabled')]
    public function testNestedJobRunsWithinTheParentJobSpanAndScope(): void
    {
        $transaction = $this->startTransaction();

        dispatch(new QueueEventsTestParentJob(new QueueEventsTestJob));

        [, $parentSpan, $childSpan] = $transaction->getSpanRecorder()->getSpans();
        $parentEvent = $this->getLastSentryEvent();

        $this->assertSame($parentSpan->getSpanId(), $childSpan->getParentSpanId());
        $this->assertSame((string) $parentSpan->getSpanId(), $parentEvent->getContexts()['trace']['span_id']);
        $this->assertSame(['Processing queue job', 'parent'], $this->breadcrumbMessages($parentEvent->getBreadcrumbs()));
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testCaughtNestedJobFailureKeepsTheParentJobSpanCurrent(): void
    {
        $transaction = $this->startTransaction();

        dispatch(new QueueEventsTestParentJob(
            new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('child'),
            catchChildFailure: true,
        ));

        [, $parentSpan, $childSpan] = $transaction->getSpanRecorder()->getSpans();

        $this->assertSame($parentSpan->getSpanId(), $childSpan->getParentSpanId());
        $this->assertSame(SpanStatus::internalError(), $childSpan->getStatus());
        $this->assertSame(SpanStatus::ok(), $parentSpan->getStatus());
        $this->assertSame((string) $parentSpan->getSpanId(), $this->getLastSentryEvent()->getContexts()['trace']['span_id']);
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
        $this->assertSame([], $this->getCurrentSentryBreadcrumbs());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testPropagatedNestedJobFailureFailsTheParentJobAndKeepsTheChildScope(): void
    {
        $transaction = $this->startTransaction();
        $exception = null;

        try {
            dispatch(new QueueEventsTestParentJob(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('child')));
        } catch (Exception $exception) {
        }

        [, $parentSpan, $childSpan] = $transaction->getSpanRecorder()->getSpans();

        $this->assertSame('This is a test exception', $exception?->getMessage());
        $this->assertSame($parentSpan->getSpanId(), $childSpan->getParentSpanId());
        $this->assertSame(SpanStatus::internalError(), $childSpan->getStatus());
        $this->assertSame(SpanStatus::internalError(), $parentSpan->getStatus());
        $this->assertNotNull($parentSpan->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
        $this->assertSame(['Processing queue job', 'child'], $this->breadcrumbMessages($this->getCurrentSentryBreadcrumbs()));
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testJobDispatchedAfterTheParentJobFailsStartsUnderTheCurrentSpan(): void
    {
        $transaction = $this->startTransaction();

        dispatch(new QueueEventsTestParentJob(
            new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('child'),
            catchChildFailure: true,
            failAndDispatchAnotherJob: true,
        ));

        [, $parentSpan, $childSpan, $nextSpan] = $transaction->getSpanRecorder()->getSpans();

        $this->assertSame($parentSpan->getSpanId(), $childSpan->getParentSpanId());
        $this->assertSame($transaction->getSpanId(), $nextSpan->getParentSpanId());
        $this->assertSame(SpanStatus::internalError(), $parentSpan->getStatus());
        $this->assertSame(SpanStatus::ok(), $nextSpan->getStatus());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
        $this->assertSame([], $this->getCurrentSentryBreadcrumbs());
    }

    #[DefineEnvironment('withQueueJobSpansDisabled')]
    public function testNestedJobWithoutASpanDoesNotFinishTheParentJobTransaction(): void
    {
        dispatch(new QueueEventsTestParentJob(new QueueEventsTestJob));

        $transactions = $this->getCapturedSentryEventsOfType(EventType::transaction());
        [[$parentEvent]] = $this->getCapturedSentryEventsOfType(EventType::event());

        $this->assertCount(1, $transactions);
        $this->assertSame(QueueEventsTestParentJob::class, $transactions[0][0]->getTransaction());
        $this->assertSame(
            $transactions[0][0]->getContexts()['trace']['span_id'],
            $parentEvent->getContexts()['trace']['span_id'],
        );
    }

    /**
     * Determine if the event has a Queue feature listener.
     */
    private function hasQueueFeatureListener(string $event): bool
    {
        $listeners = $this->app->make('events')->getRawListeners()[$event] ?? [];

        foreach ($listeners as $listener) {
            if (is_array($listener) && ($listener[0] ?? null) instanceof QueueIntegration) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the messages of the given breadcrumbs.
     *
     * @param array<array-key, Breadcrumb> $breadcrumbs
     *
     * @return list<null|string>
     */
    private function breadcrumbMessages(array $breadcrumbs): array
    {
        return array_values(array_map(
            static fn (Breadcrumb $breadcrumb): ?string => $breadcrumb->getMessage(),
            $breadcrumbs,
        ));
    }
}

class QueueEventsTestJob implements ShouldQueue
{
    /**
     * Execute the job.
     */
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

/**
 * Add a test breadcrumb with the given message.
 */
function queueEventsTestAddTestBreadcrumb(?string $message = null): void
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
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();
    }
}

class QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb implements ShouldQueue
{
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();

        captureException(new Exception('This is a test exception'));
    }
}

class QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb implements ShouldQueue
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        private ?string $message = null,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb($this->message);

        throw new Exception('This is a test exception');
    }
}

class QueueEventsTestParentJob implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private ShouldQueue $child,
        private bool $catchChildFailure = false,
        private bool $failAndDispatchAnotherJob = false,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb('parent');

        try {
            dispatch($this->child);
        } catch (Exception $exception) {
            if (! $this->catchChildFailure) {
                throw $exception;
            }
        }

        if ($this->failAndDispatchAnotherJob) {
            $this->fail();

            dispatch(new QueueEventsTestJob);
        }

        captureException(new Exception('Reported by the parent job'));
    }
}
