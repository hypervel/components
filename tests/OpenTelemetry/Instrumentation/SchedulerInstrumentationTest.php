<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\SchedulerInstrumentation;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\OpenTelemetry\Fixtures\CapturingAlwaysOffSampler;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
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
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;

class SchedulerInstrumentationTest extends TestCase
{
    private const string DURATION_METRIC = 'hypervel.scheduler.task.duration';

    private const string EXECUTIONS_METRIC = 'hypervel.scheduler.task.executions';

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private SchedulerInstrumentationTestClock $clock;

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
        $this->clock = new SchedulerInstrumentationTestClock;
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
        $this->instrumentation()->register($this->options(
            traces: false,
            duration: false,
            executions: false,
        ));

        $this->assertFalse($this->events->hasListeners(ScheduledTaskStarting::class));
        $this->assertFalse($this->events->hasListeners(ScheduledTaskFinished::class));
        $this->assertFalse($this->events->hasListeners(ScheduledTaskFailed::class));
        $this->assertSame(0, $this->clock->calls);
    }

    public function testSuccessfulCommandIsAnIndependentRootSpanAndRecordsMetrics(): void
    {
        $this->instrumentation()->register($this->options());
        $task = new SchedulerInstrumentationTask('reports:daily --force');
        $ambient = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $ambient->activate();

        try {
            $this->clock->timestamp = 1_000_000_000;
            $this->events->dispatch(new ScheduledTaskStarting($task));
            $this->assertNotSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());

            $task->setTestExitCode(0);
            $this->clock->timestamp = 4_000_000_000;
            $this->events->dispatch(new ScheduledTaskFinished($task, 3.0));
            $this->assertSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        } finally {
            $ambientScope->detach();
            $ambient->end();
        }

        $span = $this->exportedSpan('schedule reports:daily');
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame(SpanContext::getInvalid()->getSpanId(), $span->getParentSpanId());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(4_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('reports:daily', $span->getAttributes()->get('hypervel.scheduler.task'));
        $this->assertSame('success', $span->getAttributes()->get('result'));
        $this->assertSame(0, $span->getAttributes()->get('hypervel.scheduler.exit_code'));

        $this->metricReader->collect();
        $this->assertSame(3, $this->histogramPoint(self::DURATION_METRIC)->sum);
        $execution = $this->sumPoint(self::EXECUTIONS_METRIC);
        $this->assertSame(1, $execution->value);
        $this->assertSame('reports:daily', $execution->attributes->get('hypervel.scheduler.task'));
        $this->assertSame('success', $execution->attributes->get('result'));
    }

    public function testUsesDescriptionCallbackAndProcessAsStableTaskIdentities(): void
    {
        $this->instrumentation()->register($this->options(
            traces: false,
            duration: false,
            executions: true,
        ));
        $described = (new SchedulerInstrumentationTask('reports:daily --force'))->description('daily reports');
        $callback = new CallbackEvent(new SchedulerInstrumentationEventMutex, static fn (): null => null);
        $process = new SchedulerInstrumentationTask('curl https://example.test', true);

        foreach ([$described, $callback, $process] as $task) {
            $this->events->dispatch(new ScheduledTaskStarting($task));
            $this->events->dispatch(new ScheduledTaskFinished($task, 0.0));
        }

        $this->assertSame(0, $this->clock->calls);
        $this->metricReader->collect();
        $metric = $this->metric(self::EXECUTIONS_METRIC);
        $this->assertInstanceOf(Sum::class, $metric->data);
        $tasks = array_map(
            static fn (NumberDataPoint $point): mixed => $point->attributes->get('hypervel.scheduler.task'),
            $metric->data->dataPoints,
        );
        sort($tasks);

        $this->assertSame(['callback', 'daily reports', 'process'], $tasks);
    }

    public function testNonZeroExitMarksFailureAndLaterFailureEventDoesNotCompleteTwice(): void
    {
        $this->exceptionContexts->enable();
        $this->instrumentation()->register($this->options());
        $task = new SchedulerInstrumentationTask('reports:daily');
        $laterException = new RuntimeException('Exit status reported afterward.');

        $this->events->dispatch(new ScheduledTaskStarting($task));
        $task->setTestExitCode(7);
        $this->events->dispatch(new ScheduledTaskFinished($task, 0.1));
        $this->events->dispatch(new ScheduledTaskFailed($task, $laterException));

        $span = $this->exportedSpan('schedule reports:daily');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('failure', $span->getAttributes()->get('result'));
        $this->assertSame(7, $span->getAttributes()->get('hypervel.scheduler.exit_code'));
        $this->assertCount(0, $span->getEvents());
        $this->assertNull($this->exceptionContexts->take($laterException));
        $this->assertCount(1, $this->spanExporter->getSpans());

        $this->metricReader->collect();
        $this->assertSame('failure', $this->sumPoint(self::EXECUTIONS_METRIC)->attributes->get('result'));
    }

    public function testFailureRecordsTheThrowableAndHandsItsExactContextToExceptionTelemetry(): void
    {
        $this->exceptionContexts->enable();
        $this->instrumentation()->register($this->options());
        $task = new SchedulerInstrumentationTask('reports:daily');
        $exception = new RuntimeException('Task failed.');

        $this->clock->timestamp = 2_000_000_000;
        $this->events->dispatch(new ScheduledTaskStarting($task));
        $this->clock->timestamp = 4_000_000_000;
        $this->events->dispatch(new ScheduledTaskFailed($task, $exception));

        $span = $this->exportedSpan('schedule reports:daily');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Task failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $handoff = $this->exceptionContexts->take($exception);
        $this->assertNotNull($handoff);
        $this->assertSame(OperationOrigin::SCHEDULE, $handoff->origin);
        $this->assertSame($span->getSpanId(), Span::fromContext($handoff->context)->getContext()->getSpanId());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());

        $this->metricReader->collect();
        $this->assertSame(2, $this->histogramPoint(self::DURATION_METRIC)->sum);
        $this->assertSame('failure', $this->sumPoint(self::EXECUTIONS_METRIC)->attributes->get('result'));
    }

    public function testCancellationProducesNoFalseCompletionTelemetry(): void
    {
        $this->instrumentation()->register($this->options());
        $task = new SchedulerInstrumentationTask('reports:daily');

        $this->events->dispatch(new ScheduledTaskStarting($task));
        $active = Span::getCurrent()->getContext();
        $this->events->dispatch(new ScheduledTaskFailed($task, new CanceledException));

        $this->assertTrue($active->isValid());
        $this->assertSame($active->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::EXECUTIONS_METRIC)->data->dataPoints);
    }

    public function testTaskIdentityIsAvailableToTheSampler(): void
    {
        $sampler = new CapturingAlwaysOffSampler;
        $this->tracerProvider->shutdown();
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler($sampler)
            ->build();
        $this->instrumentation()->register($this->options(duration: false, executions: false));
        $task = new SchedulerInstrumentationTask('reports:daily');

        $this->events->dispatch(new ScheduledTaskStarting($task));
        $this->events->dispatch(new ScheduledTaskFinished($task, 0.0));

        $this->assertCount(1, $sampler->samples);
        $this->assertSame(
            'reports:daily',
            $sampler->samples[0]['attributes']['hypervel.scheduler.task'],
        );
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testNestedTasksOnlyCompleteTheExactTopState(): void
    {
        $this->instrumentation()->register($this->options(duration: false, executions: false));
        $outer = new SchedulerInstrumentationTask('outer');
        $inner = new SchedulerInstrumentationTask('inner');

        $this->events->dispatch(new ScheduledTaskStarting($outer));
        $outerSpan = Span::getCurrent()->getContext();
        $this->events->dispatch(new ScheduledTaskStarting($inner));
        $innerSpan = Span::getCurrent()->getContext();

        $this->events->dispatch(new ScheduledTaskFinished($outer, 0.0));
        $this->assertSame($innerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());

        $this->events->dispatch(new ScheduledTaskFinished($inner, 0.0));
        $this->assertSame($outerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->events->dispatch(new ScheduledTaskFinished($outer, 0.0));
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testConcurrentRunsOfTheSameTaskRemainIsolated(): void
    {
        $this->instrumentation()->register($this->options(duration: false, executions: false));
        $task = new SchedulerInstrumentationTask('reports:daily');

        $results = parallel([
            function () use ($task): string {
                $this->events->dispatch(new ScheduledTaskStarting($task));
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(10_000);
                $this->events->dispatch(new ScheduledTaskFinished($task, 0.01));
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
            function () use ($task): string {
                $this->events->dispatch(new ScheduledTaskStarting($task));
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(5_000);
                $this->events->dispatch(new ScheduledTaskFinished($task, 0.005));
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
        ]);

        $this->assertNotSame($results[0], $results[1]);
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    /**
     * Create scheduler instrumentation.
     */
    private function instrumentation(): SchedulerInstrumentation
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new SchedulerInstrumentation(
            $this->events,
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $this->exceptionContexts,
            $this->origins,
            ProcessIdentity::eventWorker(0),
            $logContextScopes,
        );
    }

    /**
     * Return scheduler instrumentation options.
     *
     * @return array<string, mixed>
     */
    private function options(
        bool $traces = true,
        bool $duration = true,
        bool $executions = true,
    ): array {
        return [
            'traces' => $traces,
            'metrics' => [
                self::DURATION_METRIC => $duration,
                self::EXECUTIONS_METRIC => $executions,
            ],
        ];
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
        $this->assertCount(1, $metric->data->dataPoints);

        return $metric->data->dataPoints[0];
    }

    /**
     * Return the first point from a histogram metric.
     */
    private function histogramPoint(string $name): HistogramDataPoint
    {
        $metric = $this->metric($name);
        $this->assertInstanceOf(Histogram::class, $metric->data);
        $this->assertCount(1, $metric->data->dataPoints);

        return $metric->data->dataPoints[0];
    }
}

class SchedulerInstrumentationTestClock implements ClockInterface
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

class SchedulerInstrumentationEventMutex implements EventMutex
{
    /**
     * Acquire the test mutex.
     */
    public function create(Event $event): bool
    {
        return true;
    }

    /**
     * Determine whether the test mutex exists.
     */
    public function exists(Event $event): bool
    {
        return false;
    }

    /**
     * Release the test mutex.
     */
    public function forget(Event $event): void
    {
    }
}

class SchedulerInstrumentationTask extends Event
{
    /**
     * Create a test scheduled task.
     */
    public function __construct(?string $command = null, bool $isSystem = false)
    {
        parent::__construct(new SchedulerInstrumentationEventMutex, $command, isSystem: $isSystem);
    }

    /**
     * Set the current test-run exit code.
     */
    public function setTestExitCode(int $exitCode): void
    {
        $this->setExitCode($exitCode);
    }
}
