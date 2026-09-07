<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\QueueInstrumentation;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\QueueProducerStateStore;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\SyncQueue;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Tests\OpenTelemetry\Fixtures\CapturingAlwaysOffSampler;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Override;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class QueueInstrumentationTest extends TestCase
{
    private const string SENT_MESSAGES_METRIC = 'messaging.client.sent.messages';

    private const string SEND_DURATION_METRIC = 'messaging.client.operation.duration';

    private const string CONSUMED_MESSAGES_METRIC = 'messaging.client.consumed.messages';

    private const string PROCESS_DURATION_METRIC = 'messaging.process.duration';

    private const string DEPTH_METRIC = 'hypervel.queue.jobs';

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private QueueInstrumentationTestClock $clock;

    private InMemorySpanExporter $spanExporter;

    private TracerProvider $tracerProvider;

    private InMemoryMetricExporter $metricExporter;

    private ExportingReader $metricReader;

    private MeterProvider $meterProvider;

    private ExceptionContextRegistry $exceptionContexts;

    private OperationOrigin $origins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->events = new Dispatcher;
        $this->clock = new QueueInstrumentationTestClock;
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->metricExporter = new InMemoryMetricExporter;
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->metricReader)
            ->build();
        $this->exceptionContexts = new ExceptionContextRegistry;
        $this->origins = new OperationOrigin;
    }

    protected function tearDownInCoroutine(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testRegistersNothingWhenEveryOutputIsDisabled(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'propagation' => false,
            'metrics' => false,
        ]));

        $this->assertFalse($this->events->hasListeners(JobPayloadFinalizing::class));
        $this->assertFalse($this->events->hasListeners(JobQueueing::class));
        $this->assertFalse($this->events->hasListeners(JobQueued::class));
        $this->assertFalse($this->events->hasListeners(JobQueueingFailed::class));
        $this->assertFalse($this->events->hasListeners(JobProcessing::class));
        $this->assertFalse($this->events->hasListeners(JobAttempted::class));
        $this->assertSame(0, QueueInstrumentationPayloadQueue::payloadCallbackCount());
        $this->assertSame(0, $this->clock->calls);
    }

    public function testRecordsOnePersistentProducerLifecycleWithoutActivatingItsSpan(): void
    {
        $this->instrumentation()->register($this->options());
        $event = $this->finalizingEvent();
        $ambient = Context::getCurrent();

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch($event);

        $this->assertSame($ambient, Context::getCurrent());
        $this->assertFalse($this->events->hasListeners(JobQueueing::class));
        $decoded = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey(TraceContextPropagator::TRACEPARENT, $decoded);
        $producerContext = TraceContextPropagator::getInstance()->extract(
            $decoded,
            ArrayAccessGetterSetter::getInstance(),
            Context::getRoot(),
        );

        $this->clock->timestamp = 3_000_000_000;
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));

        $span = $this->exportedSpan('enqueue emails');
        $attributes = $span->getAttributes();
        $this->assertSame(SpanKind::KIND_PRODUCER, $span->getKind());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(3_000_000_000, $span->getEndEpochNanos());
        $this->assertSame(
            Span::fromContext($producerContext)->getContext()->getSpanId(),
            $span->getSpanId(),
        );
        $this->assertSame('redis', $attributes->get(MessagingIncubatingAttributes::MESSAGING_SYSTEM));
        $this->assertSame('emails', $attributes->get(MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME));
        $this->assertSame('enqueue', $attributes->get(MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME));
        $this->assertSame(
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND,
            $attributes->get(MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE),
        );
        $this->assertSame('redis', $attributes->get('hypervel.queue.connection'));
        $this->assertSame('job-uuid', $attributes->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID));
        $this->assertSame(
            strlen($event->payload),
            $attributes->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE),
        );

        $this->metricReader->collect();
        $sent = $this->sumPoint(self::SENT_MESSAGES_METRIC);
        $duration = $this->histogramPoint(self::SEND_DURATION_METRIC);
        $this->assertSame(1, $sent->value);
        $this->assertSame(2, $duration->sum);
        $this->assertNull($sent->attributes->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID));
        $this->assertNull($duration->attributes->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE));
    }

    public function testProducerWithoutUuidCompletesThroughExactPayloadCorrelation(): void
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['uuid']);

        $this->assertProducerWithoutStringUuid(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testProducerWithNonStringUuidCompletesThroughExactPayloadCorrelation(): void
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['uuid'] = 42;

        $this->assertProducerWithoutStringUuid(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testDuplicateUuidLessPayloadsCompleteEveryTracedProducerAcrossMixedOutcomes(): void
    {
        $this->instrumentation()->register($this->options([
            'propagation' => false,
        ]));
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['uuid']);
        $payload = json_encode($payload, JSON_THROW_ON_ERROR);
        $first = $this->finalizingEvent(payload: $payload);
        $second = $this->finalizingEvent(payload: $payload);
        $exception = new RuntimeException('Queueing failed.');

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch($first);
        $this->clock->timestamp = 2_000_000_000;
        $this->events->dispatch($second);
        $this->assertSame($first->payload, $second->payload);

        $this->clock->timestamp = 3_000_000_000;
        $this->events->dispatch(new JobQueued(
            'redis',
            'emails',
            42,
            'SendEmail@handle',
            $first->payload,
            null,
        ));
        $this->clock->timestamp = 5_000_000_000;
        $this->events->dispatch(new JobQueueingFailed(
            'redis',
            'emails',
            'SendEmail@handle',
            $second->payload,
            null,
            $exception,
        ));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(2, $spans);
        $errorSpans = array_values(array_filter(
            $spans,
            static fn (SpanDataInterface $span): bool => $span->getStatus()->getCode() === StatusCode::STATUS_ERROR,
        ));
        $this->assertCount(1, $errorSpans);
        $this->assertSame(
            RuntimeException::class,
            $errorSpans[0]->getAttributes()->get(ErrorAttributes::ERROR_TYPE),
        );
        $this->assertNull(QueueProducerStateStore::current()->take($payload));

        $this->metricReader->collect();
        $sent = $this->metric(self::SENT_MESSAGES_METRIC);
        $this->assertInstanceOf(Sum::class, $sent->data);
        $sentPoints = $sent->data->dataPoints;
        $this->assertIsArray($sentPoints);
        $this->assertCount(2, $sentPoints);
        $sentCount = 0;
        $sentErrorCount = 0;

        foreach ($sentPoints as $point) {
            $sentCount += $point->value;

            if ($point->attributes->has(ErrorAttributes::ERROR_TYPE)) {
                ++$sentErrorCount;
                $this->assertSame(
                    RuntimeException::class,
                    $point->attributes->get(ErrorAttributes::ERROR_TYPE),
                );
            }
        }

        $this->assertSame(2, $sentCount);
        $this->assertSame(1, $sentErrorCount);

        $duration = $this->metric(self::SEND_DURATION_METRIC);
        $this->assertInstanceOf(Histogram::class, $duration->data);
        $durationPoints = $duration->data->dataPoints;
        $this->assertIsArray($durationPoints);
        $this->assertCount(2, $durationPoints);
        $durationCount = 0;
        $durationSum = 0;
        $durationErrorCount = 0;

        foreach ($durationPoints as $point) {
            $durationCount += $point->count;
            $durationSum += $point->sum;

            if ($point->attributes->has(ErrorAttributes::ERROR_TYPE)) {
                ++$durationErrorCount;
                $this->assertSame(
                    RuntimeException::class,
                    $point->attributes->get(ErrorAttributes::ERROR_TYPE),
                );
            }
        }

        $this->assertSame(2, $durationCount);
        $this->assertSame(5, $durationSum);
        $this->assertSame(1, $durationErrorCount);
    }

    public function testDuplicateUuidLessPayloadsCompleteEveryMetricsOnlyProducer(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'propagation' => false,
            'metrics' => $this->metrics(sent: true, sendDuration: true),
        ]));
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['uuid']);
        $payload = json_encode($payload, JSON_THROW_ON_ERROR);
        $first = $this->finalizingEvent(payload: $payload);
        $second = $this->finalizingEvent(payload: $payload);

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch($first);
        $this->clock->timestamp = 2_000_000_000;
        $this->events->dispatch($second);
        $this->clock->timestamp = 3_000_000_000;
        $this->events->dispatch(new JobQueued(
            'redis',
            'emails',
            42,
            'SendEmail@handle',
            $first->payload,
            null,
        ));
        $this->clock->timestamp = 5_000_000_000;
        $this->events->dispatch(new JobQueued(
            'redis',
            'emails',
            43,
            'SendEmail@handle',
            $second->payload,
            null,
        ));

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->assertNull(QueueProducerStateStore::current()->take($payload));
        $this->metricReader->collect();
        $this->assertSame(2, $this->sumPoint(self::SENT_MESSAGES_METRIC)->value);
        $duration = $this->histogramPoint(self::SEND_DURATION_METRIC);
        $this->assertSame(2, $duration->count);
        $this->assertSame(5, $duration->sum);
    }

    public function testTracingWithoutPropagationKeepsTheOriginalPayloadAndStillCorrelates(): void
    {
        $this->instrumentation()->register($this->options([
            'propagation' => false,
            'metrics' => false,
        ]));
        $event = $this->finalizingEvent();
        $originalPayload = $event->payload;

        $this->clock->timestamp = 10;
        $this->events->dispatch($event);
        $this->assertSame($originalPayload, $event->payload);

        $this->clock->timestamp = 20;
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));

        $span = $this->exportedSpan('enqueue emails');
        $this->assertSame(
            strlen($originalPayload),
            $span->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE),
        );
        $this->assertSame([], $this->metricExporter->collect());
    }

    public function testTraceDisabledProducerMetricsDoNotDecodeThePayload(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'propagation' => false,
            'metrics' => $this->metrics(
                sent: true,
                sendDuration: true,
            ),
        ]));
        $event = $this->finalizingEvent(payload: 'not-json');

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch($event);
        $this->clock->timestamp = 2_500_000_000;
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));

        $this->assertSame('not-json', $event->payload);
        $this->assertSame(2, $this->clock->calls);
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame(1, $this->sumPoint(self::SENT_MESSAGES_METRIC)->value);
        $this->assertSame(1.5, $this->histogramPoint(self::SEND_DURATION_METRIC)->sum);
    }

    public function testCounterOnlyProducerMetricsDoNotReadTheClock(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'propagation' => false,
            'metrics' => $this->metrics(sent: true),
        ]));
        $event = $this->finalizingEvent(payload: 'not-json');

        $this->events->dispatch($event);
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));

        $this->assertSame(0, $this->clock->calls);
        $this->metricReader->collect();
        $this->assertSame(1, $this->sumPoint(self::SENT_MESSAGES_METRIC)->value);
    }

    public function testConsumerOnlyCounterRegistersNoProducerOrCompletionListeners(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'propagation' => false,
            'metrics' => $this->metrics(consumed: true),
        ]));
        $job = $this->job($this->payload());

        $this->assertFalse($this->events->hasListeners(JobPayloadFinalizing::class));
        $this->assertFalse($this->events->hasListeners(JobQueued::class));
        $this->assertFalse($this->events->hasListeners(JobAttempted::class));

        $this->events->dispatch(new JobProcessing('redis', $job));

        $this->assertSame(0, $this->clock->calls);
        $this->metricReader->collect();
        $this->assertSame(1, $this->sumPoint(self::CONSUMED_MESSAGES_METRIC)->value);
    }

    public function testOrdinaryFinalizerFailureEndsAndAssociatesTheStartedSpanWithoutCommitting(): void
    {
        $exception = new RuntimeException('Propagation failed.');
        $this->exceptionContexts->enable();
        $this->instrumentation(new QueueInstrumentationTestPropagator($exception))->register($this->options());
        $event = $this->finalizingEvent();
        $originalPayload = $event->payload;

        try {
            $this->events->dispatch($event);
            $this->fail('The finalizer failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame($originalPayload, $event->payload);
        $this->assertNull(QueueProducerStateStore::current()->take($originalPayload));
        $span = $this->exportedSpan('enqueue emails');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_TYPE));
        $handoff = $this->exceptionContexts->take($exception);
        $this->assertNotNull($handoff);
        $this->assertSame($span->getSpanId(), Span::fromContext($handoff->context)->getContext()->getSpanId());

        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::SENT_MESSAGES_METRIC)->data->dataPoints);
        $this->assertCount(0, $this->metric(self::SEND_DURATION_METRIC)->data->dataPoints);
    }

    public function testFinalizerCancellationAbandonsTheSpanAndCommitsNothing(): void
    {
        $cancellation = new CanceledException;
        $this->instrumentation(new QueueInstrumentationTestPropagator($cancellation))->register($this->options());
        $event = $this->finalizingEvent();
        $originalPayload = $event->payload;

        try {
            $this->events->dispatch($event);
            $this->fail('Cancellation was not rethrown.');
        } catch (CanceledException $caught) {
            $this->assertSame($cancellation, $caught);
        }

        $this->assertSame($originalPayload, $event->payload);
        $this->assertNull(QueueProducerStateStore::current()->take($originalPayload));
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::SENT_MESSAGES_METRIC)->data->dataPoints);
    }

    public function testTraceDisabledPropagationFailureCommitsNoMetricState(): void
    {
        $exception = new RuntimeException('Propagation failed.');
        $this->instrumentation(new QueueInstrumentationTestPropagator($exception))->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => $this->metrics(sent: true, sendDuration: true),
        ]));
        $event = $this->finalizingEvent();
        $originalPayload = $event->payload;

        try {
            $this->events->dispatch($event);
            $this->fail('The finalizer failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame($originalPayload, $event->payload);
        $this->assertNull(QueueProducerStateStore::current()->take($originalPayload));
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::SENT_MESSAGES_METRIC)->data->dataPoints);
    }

    public function testLaterFinalizerRewriteFallsBackToTheFrameworkUuid(): void
    {
        $this->instrumentation()->register($this->options([
            'propagation' => false,
            'metrics' => false,
        ]));
        $event = $this->finalizingEvent();
        $originalPayload = $event->payload;
        $this->events->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event): void {
            $payload = $event->payload();
            $payload['later'] = true;
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });

        $this->events->dispatch($event);
        $this->assertNotSame($originalPayload, $event->payload);
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));

        $span = $this->exportedSpan('enqueue emails');
        $this->assertSame(
            strlen($originalPayload),
            $span->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE),
        );
        $this->assertNull(QueueProducerStateStore::current()->takeUuid('job-uuid'));
    }

    public function testEarlierFinalizerFailureCreatesNoProducerState(): void
    {
        $exception = new RuntimeException('Earlier finalizer failed.');
        $this->events->listen(JobPayloadFinalizing::class, static function () use ($exception): never {
            throw $exception;
        });
        $this->instrumentation()->register($this->options());
        $queue = $this->lifecycleQueue();

        try {
            $queue->push('SendEmail@handle');
            $this->fail('The earlier finalizer failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($queue->stored);
        $this->assertNull(QueueProducerStateStore::current()->takeUuid('job-uuid'));
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testLaterFinalizerFailureCompletesMetricsOnlyProducerByUuid(): void
    {
        $exception = new RuntimeException('Later finalizer failed.');
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => $this->metrics(sent: true, sendDuration: true),
        ]));
        $this->events->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event) use ($exception): never {
            $payload = $event->payload();
            $payload['later'] = true;
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);

            throw $exception;
        });
        $queue = $this->lifecycleQueue();

        try {
            $queue->push('SendEmail@handle');
            $this->fail('The later finalizer failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($queue->stored);
        $this->assertNull(QueueProducerStateStore::current()->takeUuid('job-uuid'));
        $this->metricReader->collect();
        $this->assertSame(1, $this->sumPoint(self::SENT_MESSAGES_METRIC)->value);
        $this->assertSame(
            RuntimeException::class,
            $this->sumPoint(self::SENT_MESSAGES_METRIC)->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
    }

    public function testQueueingListenerFailureCompletesProducerState(): void
    {
        $exception = new RuntimeException('Queueing listener failed.');
        $this->instrumentation()->register($this->options());
        $this->events->listen(JobQueueing::class, static function () use ($exception): never {
            throw $exception;
        });
        $queue = $this->lifecycleQueue();

        try {
            $queue->push('SendEmail@handle');
            $this->fail('The queueing-listener failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($queue->stored);
        $this->assertNull(QueueProducerStateStore::current()->takeUuid('job-uuid'));
        $this->assertSame(StatusCode::STATUS_ERROR, $this->exportedSpan('enqueue emails')->getStatus()->getCode());
    }

    public function testTransportFailureCompletesProducerState(): void
    {
        $exception = new RuntimeException('Transport failed.');
        $this->instrumentation()->register($this->options());
        $queue = $this->lifecycleQueue($exception);

        try {
            $queue->push('SendEmail@handle');
            $this->fail('The transport failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertFalse($queue->stored);
        $this->assertNull(QueueProducerStateStore::current()->takeUuid('job-uuid'));
        $this->assertSame(StatusCode::STATUS_ERROR, $this->exportedSpan('enqueue emails')->getStatus()->getCode());
    }

    public function testAcceptedJobRemainsSuccessfulWhenLaterQueuedListenerThrows(): void
    {
        $exception = new RuntimeException('Later queued listener failed.');
        $this->instrumentation()->register($this->options());
        $this->events->listen(JobQueued::class, static function () use ($exception): never {
            throw $exception;
        });
        $queue = $this->lifecycleQueue();

        try {
            $queue->push('SendEmail@handle');
            $this->fail('The later queued-listener failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertTrue($queue->stored);
        $this->assertNull(QueueProducerStateStore::current()->takeUuid('job-uuid'));
        $this->assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan('enqueue emails')->getStatus()->getCode());
    }

    public function testMidBatchFinalizerFailureDoesNotCompleteAnEarlierSibling(): void
    {
        $exception = new RuntimeException('Second propagation failed.');
        $propagator = new QueueInstrumentationTestPropagator($exception, 2);
        $this->instrumentation($propagator)->register($this->options());
        $first = $this->finalizingEvent(uuid: 'first-uuid');
        $second = $this->finalizingEvent(uuid: 'second-uuid');

        $this->events->dispatch($first);

        try {
            $this->events->dispatch($second);
            $this->fail('The second finalizer failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        $this->assertNotNull(QueueProducerStateStore::current()->take($first->payload));
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::SENT_MESSAGES_METRIC)->data->dataPoints);
    }

    public function testConsumerSpanUsesTheExtractedProducerAsParentAndRecordsMetrics(): void
    {
        $instrumentation = $this->instrumentation();
        $instrumentation->register($this->options([
            'metrics' => $this->metrics(consumed: true, processDuration: true),
        ]));
        $producerContext = $this->remoteContext();
        $payload = $this->payloadWithContext($producerContext);
        $job = $this->job($payload);

        $this->clock->timestamp = 2_000_000_000;
        $this->events->dispatch(new JobProcessing('redis', $job));
        $activeConsumer = Span::getCurrent()->getContext();
        $this->assertTrue($activeConsumer->isValid());
        $this->assertSame(1, $instrumentation->consumerStateCount());

        $this->clock->timestamp = 5_000_000_000;
        $this->events->dispatch(new JobAttempted('redis', $job));

        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertSame(0, $instrumentation->consumerStateCount());
        $span = $this->exportedSpan('process emails');
        $producerSpanContext = Span::fromContext($producerContext)->getContext();
        $this->assertSame($producerSpanContext->getTraceId(), $span->getTraceId());
        $this->assertSame($producerSpanContext->getSpanId(), $span->getParentSpanId());
        $this->assertSame('job-uuid', $span->getAttributes()->get(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID));

        $this->metricReader->collect();
        $this->assertSame(1, $this->sumPoint(self::CONSUMED_MESSAGES_METRIC)->value);
        $this->assertSame(3, $this->histogramPoint(self::PROCESS_DURATION_METRIC)->sum);
    }

    public function testConsumerSpanRetainsAnAmbientParentAndLinksTheProducer(): void
    {
        $this->instrumentation()->register($this->options(['metrics' => false]));
        $producerContext = $this->remoteContext();
        $job = $this->job($this->payloadWithContext($producerContext));
        $ambientSpan = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $ambientSpan->activate();

        try {
            $this->events->dispatch(new JobProcessing('redis', $job));
            $this->events->dispatch(new JobAttempted('redis', $job));
            $this->assertSame($ambientSpan->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        } finally {
            $ambientScope->detach();
            $ambientSpan->end();
        }

        $span = $this->exportedSpan('process emails');
        $this->assertSame($ambientSpan->getContext()->getSpanId(), $span->getParentSpanId());
        $this->assertCount(1, $span->getLinks());
        $this->assertSame(
            Span::fromContext($producerContext)->getContext()->getSpanId(),
            $span->getLinks()[0]->getSpanContext()->getSpanId(),
        );
    }

    public function testPropagationOnlyActivatesAndDetachesTheRemoteProducerContext(): void
    {
        $instrumentation = $this->instrumentation();
        $instrumentation->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => false,
        ]));
        $producerContext = $this->remoteContext();
        $job = $this->job($this->payloadWithContext($producerContext));

        $this->events->dispatch(new JobProcessing('redis', $job));
        $this->assertSame(
            Span::fromContext($producerContext)->getContext()->getSpanId(),
            Span::getCurrent()->getContext()->getSpanId(),
        );
        $this->assertSame(1, $instrumentation->consumerStateCount());

        $this->events->dispatch(new JobAttempted('redis', $job));

        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertSame(0, $instrumentation->consumerStateCount());
        $this->assertSame(0, $this->clock->calls);
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testPropagationOnlySyncConsumerUsesAmbientContextWithoutExtractionOrState(): void
    {
        $propagator = new QueueInstrumentationTestPropagator;
        $instrumentation = $this->instrumentation(
            $propagator,
            $this->configuration(['sync' => ['driver' => 'sync']]),
        );
        $instrumentation->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => false,
        ]));
        $job = m::mock(Job::class);
        $job->shouldNotReceive('payload');
        $ambient = Context::getCurrent();

        $this->events->dispatch(new JobProcessing('sync', $job));

        $this->assertSame($ambient, Context::getCurrent());
        $this->assertSame(0, $propagator->extractCalls);
        $this->assertSame(0, $instrumentation->consumerStateCount());
        $this->assertSame(0, $this->clock->calls);

        $this->events->dispatch(new JobAttempted('sync', $job));

        $this->assertSame(0, $instrumentation->consumerStateCount());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame([], $this->metricExporter->collect());
    }

    public function testPropagationOnlyConsumerWithoutAValidCarrierRetainsNoState(): void
    {
        $propagator = new QueueInstrumentationTestPropagator;
        $instrumentation = $this->instrumentation($propagator);
        $instrumentation->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => false,
        ]));
        $job = $this->job($this->payload());

        $this->events->dispatch(new JobProcessing('redis', $job));

        $this->assertSame(1, $propagator->extractCalls);
        $this->assertSame(0, $instrumentation->consumerStateCount());

        $this->events->dispatch(new JobAttempted('redis', $job));

        $this->assertSame(0, $instrumentation->consumerStateCount());
        $this->assertSame(0, $this->clock->calls);
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testQueueIdentityIsAvailableToTheSamplerWithoutMessageDetails(): void
    {
        $sampler = new CapturingAlwaysOffSampler;
        $this->tracerProvider->shutdown();
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler($sampler)
            ->build();
        $instrumentation = $this->instrumentation();
        $instrumentation->register($this->options([
            'propagation' => false,
            'metrics' => false,
        ]));
        $event = $this->finalizingEvent();
        $job = $this->job($this->payload());

        $this->events->dispatch($event);
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));
        $this->events->dispatch(new JobProcessing('redis', $job));
        $this->events->dispatch(new JobAttempted('redis', $job));

        $this->assertCount(2, $sampler->samples);

        foreach ([
            ['sample' => $sampler->samples[0], 'operation' => 'enqueue', 'type' => MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND],
            ['sample' => $sampler->samples[1], 'operation' => 'process', 'type' => MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_PROCESS],
        ] as $expected) {
            $attributes = $expected['sample']['attributes'];
            $this->assertSame('redis', $attributes[MessagingIncubatingAttributes::MESSAGING_SYSTEM]);
            $this->assertSame('emails', $attributes[MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME]);
            $this->assertSame($expected['operation'], $attributes[MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME]);
            $this->assertSame($expected['type'], $attributes[MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE]);
            $this->assertSame('redis', $attributes['hypervel.queue.connection']);
            $this->assertArrayNotHasKey(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID, $attributes);
            $this->assertArrayNotHasKey(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE, $attributes);
        }

        $this->assertSame(0, $instrumentation->consumerStateCount());
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testConsumerFailureIsRecordedOnceAndHandedToExceptionTelemetry(): void
    {
        $this->exceptionContexts->enable();
        $this->instrumentation()->register($this->options([
            'propagation' => false,
            'metrics' => $this->metrics(processDuration: true),
        ]));
        $job = $this->job($this->payload());
        $exception = new RuntimeException('Job failed.');
        $laterException = new RuntimeException('Later failure.');

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch(new JobProcessing('redis', $job));
        $this->events->dispatch(new JobExceptionOccurred('redis', $job, $exception));
        $this->events->dispatch(new JobFailed('redis', $job, $laterException));
        $this->clock->timestamp = 2_000_000_000;
        $this->events->dispatch(new JobAttempted('redis', $job, $laterException));
        $this->events->dispatch(new JobAttempted('redis', $job, $laterException));

        $span = $this->exportedSpan('process emails');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Job failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $this->assertNotNull($this->exceptionContexts->take($exception));
        $this->assertNull($this->exceptionContexts->take($laterException));

        $this->metricReader->collect();
        $point = $this->histogramPoint(self::PROCESS_DURATION_METRIC);
        $this->assertSame(1, $point->sum);
        $this->assertSame(RuntimeException::class, $point->attributes->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testConsumerTimeoutUsesTheStableTimeoutErrorType(): void
    {
        $this->instrumentation()->register($this->options([
            'propagation' => false,
            'metrics' => $this->metrics(processDuration: true),
        ]));
        $job = $this->job($this->payload());

        $this->events->dispatch(new JobProcessing('redis', $job));
        $this->events->dispatch(new JobTimedOut('redis', $job));
        $this->events->dispatch(new JobAttempted('redis', $job));

        $span = $this->exportedSpan('process emails');
        $this->assertSame(TimeoutExceededException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertCount(0, $span->getEvents());
        $this->metricReader->collect();
        $this->assertSame(
            TimeoutExceededException::class,
            $this->histogramPoint(self::PROCESS_DURATION_METRIC)
                ->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
    }

    public function testLocalAsynchronousConnectionsReceiveFlatPropagationOnly(): void
    {
        $this->instrumentation(
            configuration: $this->configuration([
                'background' => ['driver' => 'background'],
                'deferred' => ['driver' => 'deferred'],
                'redis' => ['driver' => 'redis'],
            ]),
        )->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => false,
        ]));
        $scope = $this->remoteContext()->activate();

        try {
            $background = (new QueueInstrumentationPayloadQueue)
                ->setConnectionName('background')
                ->payload('SendEmail@handle');
            $redis = (new QueueInstrumentationPayloadQueue)
                ->setConnectionName('redis')
                ->payload('SendEmail@handle');
        } finally {
            $scope->detach();
        }

        $this->assertSame(1, QueueInstrumentationPayloadQueue::payloadCallbackCount());
        $this->assertArrayHasKey(TraceContextPropagator::TRACEPARENT, $background);
        $this->assertArrayNotHasKey(TraceContextPropagator::TRACEPARENT, $redis);
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testPersistentOnlyConnectionsRegisterNoStaticPayloadHook(): void
    {
        $this->instrumentation(
            configuration: $this->configuration(['redis' => ['driver' => 'redis']]),
        )->register($this->options([
            'traces' => false,
            'propagation' => true,
            'metrics' => false,
        ]));

        $this->assertSame(0, QueueInstrumentationPayloadQueue::payloadCallbackCount());
    }

    public function testDepthCollectionRunsOnlyInEventWorkerZeroAndIsolatesBackendFailures(): void
    {
        $queues = m::mock(QueueManager::class);
        $failedQueue = m::mock(QueueContract::class);
        $healthyQueue = m::mock(QueueContract::class);
        $queues->shouldReceive('connection')->twice()->with('failed')->andReturn($failedQueue);
        $queues->shouldReceive('connection')->twice()->with('redis')->andReturn($healthyQueue);
        $failedQueue->shouldReceive('size')->twice()->with('bad')->andThrow(new RuntimeException('Unavailable.'));
        $healthyQueue->shouldReceive('size')->twice()->with('emails')->andReturn(7);
        $this->instrumentation(queues: $queues)->register($this->options([
            'traces' => false,
            'propagation' => false,
            'depth_queues' => [
                'failed' => ['bad'],
                'redis' => ['emails'],
            ],
            'metrics' => $this->metrics(depth: true),
        ]));

        $this->assertSame([], $this->metricExporter->collect());
        $this->metricReader->collect();

        $metric = $this->metric(self::DEPTH_METRIC);
        $this->assertInstanceOf(Gauge::class, $metric->data);
        $points = $metric->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);
        $this->assertSame(7, $points[0]->value);
        $this->assertSame('redis', $points[0]->attributes->get('hypervel.queue.connection'));
        $this->assertSame(
            'emails',
            $points[0]->attributes->get(MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME),
        );
    }

    public function testDepthCollectionRegistersNothingOutsideEventWorkerZero(): void
    {
        $queues = m::mock(QueueManager::class);
        $queues->shouldNotReceive('connection');
        $this->instrumentation(
            queues: $queues,
            identity: ProcessIdentity::eventWorker(1),
        )->register($this->options([
            'traces' => false,
            'propagation' => false,
            'depth_queues' => ['redis' => ['emails']],
            'metrics' => $this->metrics(depth: true),
        ]));

        $this->metricReader->collect();

        $this->assertSame([], $this->metricExporter->collect());
    }

    /**
     * Create queue instrumentation.
     */
    private function instrumentation(
        ?TextMapPropagatorInterface $propagator = null,
        ?Repository $configuration = null,
        ?QueueManager $queues = null,
        ?ProcessIdentity $identity = null,
    ): QueueInstrumentationTestInstrumentation {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new QueueInstrumentationTestInstrumentation(
            $this->events,
            $configuration ?? $this->configuration(),
            $queues ?? m::mock(QueueManager::class),
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $propagator ?? TraceContextPropagator::getInstance(),
            $this->exceptionContexts,
            $this->origins,
            $identity ?? ProcessIdentity::eventWorker(0),
            $logContextScopes,
        );
    }

    /**
     * Create queue configuration.
     *
     * @param array<string, array<string, mixed>> $connections
     */
    private function configuration(array $connections = ['redis' => ['driver' => 'redis']]): Repository
    {
        return new Repository(['queue' => ['connections' => $connections]]);
    }

    /**
     * Create a final-payload event.
     */
    private function finalizingEvent(
        string $uuid = 'job-uuid',
        ?string $payload = null,
    ): JobPayloadFinalizing {
        return new JobPayloadFinalizing(
            'redis',
            'emails',
            'SendEmail@handle',
            $payload ?? $this->payload($uuid),
            null,
        );
    }

    /**
     * Create an encoded queue payload.
     */
    private function payload(string $uuid = 'job-uuid'): string
    {
        return json_encode([
            'uuid' => $uuid,
            'displayName' => 'SendEmail',
            'data' => ['command' => 'serialized'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Create a queue job mock.
     */
    private function job(string $payload, string $queue = 'emails'): Job
    {
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $job = m::mock(Job::class);
        $job->shouldReceive('payload')->zeroOrMoreTimes()->andReturn($decoded);
        $job->shouldReceive('getQueue')->zeroOrMoreTimes()->andReturn($queue);
        $job->shouldReceive('uuid')->zeroOrMoreTimes()->andReturn($decoded['uuid'] ?? null);
        $job->shouldReceive('getRawBody')->zeroOrMoreTimes()->andReturn($payload);

        return $job;
    }

    /**
     * Create a queue that exposes the persistent enqueue lifecycle.
     */
    private function lifecycleQueue(?Throwable $failure = null): QueueInstrumentationLifecycleQueue
    {
        $container = new Container;
        $container->instance('events', $this->events);

        $queue = new QueueInstrumentationLifecycleQueue($failure);
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        return $queue;
    }

    /**
     * Create a remote producer context.
     */
    private function remoteContext(): ContextInterface
    {
        return Span::wrap(SpanContext::createFromRemoteParent(
            '0123456789abcdef0123456789abcdef',
            '0123456789abcdef',
            TraceFlags::SAMPLED,
        ))->storeInContext(Context::getRoot());
    }

    /**
     * Create an encoded queue payload carrying a remote producer context.
     */
    private function payloadWithContext(ContextInterface $context): string
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        TraceContextPropagator::getInstance()->inject(
            $payload,
            ArrayAccessGetterSetter::getInstance(),
            $context,
        );

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Return normalized queue options.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_replace([
            'traces' => true,
            'propagation' => true,
            'depth_queues' => [],
            'metrics' => $this->metrics(
                sent: true,
                sendDuration: true,
                consumed: true,
                processDuration: true,
            ),
        ], $overrides);
    }

    /**
     * Return independently controlled queue metric options.
     *
     * @return array<string, bool>
     */
    private function metrics(
        bool $sent = false,
        bool $sendDuration = false,
        bool $consumed = false,
        bool $processDuration = false,
        bool $depth = false,
    ): array {
        return [
            self::SENT_MESSAGES_METRIC => $sent,
            self::SEND_DURATION_METRIC => $sendDuration,
            self::CONSUMED_MESSAGES_METRIC => $consumed,
            self::PROCESS_DURATION_METRIC => $processDuration,
            self::DEPTH_METRIC => $depth,
        ];
    }

    /**
     * Assert that producer telemetry completes without a string UUID.
     */
    private function assertProducerWithoutStringUuid(string $payload): void
    {
        $this->instrumentation()->register($this->options());
        $event = $this->finalizingEvent(payload: $payload);

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch($event);
        $this->clock->timestamp = 3_000_000_000;
        $this->events->dispatch(new JobQueued('redis', 'emails', 42, 'SendEmail@handle', $event->payload, null));

        $span = $this->exportedSpan('enqueue emails');
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(3_000_000_000, $span->getEndEpochNanos());
        $this->assertFalse(
            $span->getAttributes()->has(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID),
        );
        $this->assertNull(QueueProducerStateStore::current()->take($event->payload));

        $this->metricReader->collect();
        $this->assertSame(1, $this->sumPoint(self::SENT_MESSAGES_METRIC)->value);
        $this->assertSame(2, $this->histogramPoint(self::SEND_DURATION_METRIC)->sum);
    }

    /**
     * Return one exported span by name.
     */
    private function exportedSpan(string $name): SpanDataInterface
    {
        foreach ($this->spanExporter->getSpans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        $this->fail("Span [{$name}] was not exported.");
    }

    /**
     * Return one exported metric by name.
     */
    private function metric(string $name): Metric
    {
        foreach ($this->metricExporter->collect() as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        $this->fail("Metric [{$name}] was not exported.");
    }

    /**
     * Return the first point from a counter metric.
     */
    private function sumPoint(string $name): NumberDataPoint
    {
        $metric = $this->metric($name);
        $this->assertInstanceOf(Sum::class, $metric->data);
        $points = $metric->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);

        return $points[0];
    }

    /**
     * Return the first point from a histogram metric.
     */
    private function histogramPoint(string $name): HistogramDataPoint
    {
        $metric = $this->metric($name);
        $this->assertInstanceOf(Histogram::class, $metric->data);
        $points = $metric->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);

        return $points[0];
    }
}

class QueueInstrumentationTestClock implements ClockInterface
{
    public int $calls = 0;

    public int $timestamp = 1_000_000_000;

    /**
     * Return the configured timestamp.
     */
    public function now(): int
    {
        ++$this->calls;

        return $this->timestamp;
    }
}

class QueueInstrumentationTestPropagator implements TextMapPropagatorInterface
{
    private int $injectCalls = 0;

    public int $extractCalls = 0;

    /**
     * Create a test propagator.
     */
    public function __construct(
        private ?Throwable $failure = null,
        private ?int $failureCall = null,
    ) {
    }

    /**
     * Return propagated fields.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return TraceContextPropagator::getInstance()->fields();
    }

    /**
     * Inject trace context or throw the configured failure.
     */
    public function inject(
        mixed &$carrier,
        ?PropagationSetterInterface $setter = null,
        ?ContextInterface $context = null,
    ): void {
        ++$this->injectCalls;

        if ($this->failure !== null
            && ($this->failureCall === null || $this->failureCall === $this->injectCalls)
        ) {
            throw $this->failure;
        }

        TraceContextPropagator::getInstance()->inject($carrier, $setter, $context);
    }

    /**
     * Extract trace context.
     */
    public function extract(
        mixed $carrier,
        ?PropagationGetterInterface $getter = null,
        ?ContextInterface $context = null,
    ): ContextInterface {
        ++$this->extractCalls;

        return TraceContextPropagator::getInstance()->extract($carrier, $getter, $context);
    }
}

class QueueInstrumentationTestInstrumentation extends QueueInstrumentation
{
    /**
     * Return the retained consumer-state count.
     */
    public function consumerStateCount(): int
    {
        return count($this->consumerStates);
    }
}

class QueueInstrumentationPayloadQueue extends SyncQueue
{
    /**
     * Create a decoded string-job payload.
     *
     * @return array<string, mixed>
     */
    public function payload(string $job): array
    {
        return json_decode($this->createPayload($job, null), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Return the payload callback count.
     */
    public static function payloadCallbackCount(): int
    {
        return count(static::$createPayloadCallbacks);
    }
}

class QueueInstrumentationLifecycleQueue extends NullQueue
{
    public bool $stored = false;

    public function __construct(private ?Throwable $failure = null)
    {
    }

    /**
     * Push a job through the persistent queue lifecycle.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = 'emails'): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue, $data),
            $queue,
            null,
            static function (self $owner): string {
                if ($owner->failure !== null) {
                    throw $owner->failure;
                }

                $owner->stored = true;

                return 'job-id';
            },
        );
    }

    /**
     * Create a deterministic test payload.
     */
    #[Override]
    protected function createPayloadArray(array|object|string $job, ?string $queue, mixed $data = ''): array
    {
        return array_replace(parent::createPayloadArray($job, $queue, $data), [
            'uuid' => 'job-uuid',
        ]);
    }
}
