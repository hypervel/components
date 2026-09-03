<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\WebSocketInstrumentation;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\Tests\OpenTelemetry\Fixtures\CapturingAlwaysOffSampler;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageHandled;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Span;
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
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

use function Hypervel\Coroutine\parallel;

class WebSocketInstrumentationTest extends TestCase
{
    private const string DURATION_METRIC = 'hypervel.websocket.message.duration';

    private const string MESSAGES_METRIC = 'hypervel.websocket.messages';

    private const string ACTIVE_CONNECTIONS_METRIC = 'hypervel.websocket.active_connections';

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private WebSocketInstrumentationTestClock $clock;

    private InMemorySpanExporter $spanExporter;

    private TracerProvider $tracerProvider;

    private InMemoryMetricExporter $metricExporter;

    private ExportingReader $metricReader;

    private MeterProvider $meterProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->events = new Dispatcher;
        $this->clock = new WebSocketInstrumentationTestClock;
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->metricExporter = new InMemoryMetricExporter;
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->metricReader)
            ->build();
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
            messages: false,
            activeConnections: false,
        ));

        $this->assertFalse($this->events->hasListeners(MessageReceived::class));
        $this->assertFalse($this->events->hasListeners(MessageHandled::class));
        $this->assertFalse($this->events->hasListeners(ConnectionOpened::class));
        $this->assertFalse($this->events->hasListeners(ConnectionClosed::class));
        $this->assertSame(0, $this->clock->calls);
    }

    public function testSuccessfulMessageRecordsSpanAndMetricsWithoutPayloadOrConnectionIdentity(): void
    {
        $this->instrumentation()->register($this->options());
        $frame = $this->frame(fd: 42, opcode: 1, data: 'private payload');

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch(new MessageReceived(42, $frame, 'reverb'));
        $active = Span::getCurrent();

        $this->assertTrue($active->getContext()->isValid());

        $this->clock->timestamp = 4_000_000_000;
        $this->events->dispatch(new MessageHandled(42, $frame, 'reverb', null));

        $this->assertFalse(Span::getCurrent()->getContext()->isValid());

        $span = $this->exportedSpan('websocket.message');
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(4_000_000_000, $span->getEndEpochNanos());
        $this->assertSame(1, $span->getAttributes()->get('hypervel.websocket.opcode'));
        $this->assertSame('reverb', $span->getAttributes()->get('hypervel.websocket.server'));
        $this->assertSame('success', $span->getAttributes()->get('result'));
        $this->assertNull($span->getAttributes()->get('hypervel.websocket.fd'));
        $this->assertNull($span->getAttributes()->get('hypervel.websocket.data'));

        $this->metricReader->collect();
        $duration = $this->histogramPoint(self::DURATION_METRIC);
        $this->assertSame(3, $duration->sum);
        $this->assertSame(1, $duration->attributes->get('hypervel.websocket.opcode'));
        $this->assertSame('reverb', $duration->attributes->get('hypervel.websocket.server'));
        $this->assertSame('success', $duration->attributes->get('result'));

        $messages = $this->sumPoint(self::MESSAGES_METRIC);
        $this->assertSame(1, $messages->value);
        $this->assertSame('success', $messages->attributes->get('result'));
        $this->assertNull($messages->attributes->get('hypervel.websocket.fd'));
        $this->assertNull($messages->attributes->get('hypervel.websocket.data'));
    }

    public function testFailureRecordsExceptionAndFailureDimensions(): void
    {
        $this->instrumentation()->register($this->options(activeConnections: false));
        $frame = $this->frame();
        $exception = new RuntimeException('Message failed.');

        $this->events->dispatch(new MessageReceived(1, $frame, 'websocket'));
        $this->clock->timestamp = 2_000_000_000;
        $this->events->dispatch(new MessageHandled(1, $frame, 'websocket', $exception));

        $span = $this->exportedSpan('websocket.message');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('failure', $span->getAttributes()->get('result'));
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Message failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));

        $this->metricReader->collect();
        $this->assertSame(
            RuntimeException::class,
            $this->histogramPoint(self::DURATION_METRIC)->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
        $this->assertSame(
            'failure',
            $this->sumPoint(self::MESSAGES_METRIC)->attributes->get('result'),
        );
    }

    public function testCancellationProducesNoFalseCompletionTelemetry(): void
    {
        $this->instrumentation()->register($this->options(activeConnections: false));
        $frame = $this->frame();

        $this->events->dispatch(new MessageReceived(1, $frame, 'websocket'));
        $active = Span::getCurrent()->getContext();
        $this->events->dispatch(new MessageHandled(1, $frame, 'websocket', new CanceledException));

        $this->assertTrue($active->isValid());
        $this->assertSame($active->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::MESSAGES_METRIC)->data->dataPoints);
        $this->assertSame(1, $this->clock->calls);
    }

    public function testMessageIdentityIsAvailableToTheSampler(): void
    {
        $sampler = new CapturingAlwaysOffSampler;
        $this->tracerProvider->shutdown();
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler($sampler)
            ->build();
        $this->instrumentation()->register($this->options(
            duration: false,
            messages: false,
            activeConnections: false,
        ));
        $frame = $this->frame(opcode: 2);

        $this->events->dispatch(new MessageReceived(1, $frame, 'reverb'));
        $this->events->dispatch(new MessageHandled(1, $frame, 'reverb', null));

        $this->assertCount(1, $sampler->samples);
        $this->assertSame(2, $sampler->samples[0]['attributes']['hypervel.websocket.opcode']);
        $this->assertSame('reverb', $sampler->samples[0]['attributes']['hypervel.websocket.server']);
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    public function testNestedMessagesOnlyCompleteTheExactTopFrame(): void
    {
        $this->instrumentation()->register($this->options(
            duration: false,
            messages: false,
            activeConnections: false,
        ));
        $outer = $this->frame(fd: 1);
        $inner = $this->frame(fd: 2);

        $this->events->dispatch(new MessageReceived(1, $outer, 'websocket'));
        $outerSpan = Span::getCurrent()->getContext();
        $this->events->dispatch(new MessageReceived(2, $inner, 'websocket'));
        $innerSpan = Span::getCurrent()->getContext();

        $this->events->dispatch(new MessageHandled(1, $outer, 'websocket', null));
        $this->assertSame($innerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());

        $this->events->dispatch(new MessageHandled(2, $inner, 'websocket', null));
        $this->assertSame($outerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->events->dispatch(new MessageHandled(1, $outer, 'websocket', null));

        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testConcurrentMessagesRemainCoroutineIsolated(): void
    {
        $this->instrumentation()->register($this->options(
            duration: false,
            messages: false,
            activeConnections: false,
        ));
        $frame = $this->frame();

        $spanIds = parallel([
            function () use ($frame): string {
                $this->events->dispatch(new MessageReceived(1, $frame, 'first'));
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(10_000);
                $this->events->dispatch(new MessageHandled(1, $frame, 'first', null));
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
            function () use ($frame): string {
                $this->events->dispatch(new MessageReceived(1, $frame, 'second'));
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(5_000);
                $this->events->dispatch(new MessageHandled(1, $frame, 'second', null));
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
        ]);

        $this->assertNotSame($spanIds[0], $spanIds[1]);
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testMessageCounterAloneUsesOnlyTheCompletionBoundary(): void
    {
        $this->instrumentation()->register($this->options(
            traces: false,
            duration: false,
            messages: true,
            activeConnections: false,
        ));
        $frame = $this->frame(opcode: 2);

        $this->assertFalse($this->events->hasListeners(MessageReceived::class));
        $this->assertTrue($this->events->hasListeners(MessageHandled::class));
        $this->assertFalse($this->events->hasListeners(ConnectionOpened::class));
        $this->assertFalse($this->events->hasListeners(ConnectionClosed::class));

        $this->events->dispatch(new MessageHandled(1, $frame, 'reverb', null));

        $this->assertSame(0, $this->clock->calls);
        $this->metricReader->collect();
        $point = $this->sumPoint(self::MESSAGES_METRIC);
        $this->assertSame(1, $point->value);
        $this->assertSame(2, $point->attributes->get('hypervel.websocket.opcode'));
        $this->assertSame('reverb', $point->attributes->get('hypervel.websocket.server'));
    }

    public function testDurationAloneUsesThePairedMessageBoundaries(): void
    {
        $this->instrumentation()->register($this->options(
            traces: false,
            duration: true,
            messages: false,
            activeConnections: false,
        ));
        $frame = $this->frame();

        $this->assertTrue($this->events->hasListeners(MessageReceived::class));
        $this->assertTrue($this->events->hasListeners(MessageHandled::class));
        $this->assertFalse($this->events->hasListeners(ConnectionOpened::class));
        $this->assertFalse($this->events->hasListeners(ConnectionClosed::class));

        $this->clock->timestamp = 1_000_000_000;
        $this->events->dispatch(new MessageReceived(1, $frame, 'websocket'));
        $this->clock->timestamp = 1_500_000_000;
        $this->events->dispatch(new MessageHandled(1, $frame, 'websocket', null));

        $this->metricReader->collect();
        $this->assertSame(0.5, $this->histogramPoint(self::DURATION_METRIC)->sum);
        $this->assertSame(2, $this->clock->calls);
    }

    public function testActiveConnectionsAloneUsesOnlyConnectionEvents(): void
    {
        $this->instrumentation()->register($this->options(
            traces: false,
            duration: false,
            messages: false,
            activeConnections: true,
        ));

        $this->assertFalse($this->events->hasListeners(MessageReceived::class));
        $this->assertFalse($this->events->hasListeners(MessageHandled::class));
        $this->assertTrue($this->events->hasListeners(ConnectionOpened::class));
        $this->assertTrue($this->events->hasListeners(ConnectionClosed::class));

        $request = new Request;
        $this->events->dispatch(new ConnectionOpened(42, $request, 'reverb'));
        $this->events->dispatch(new ConnectionOpened(43, $request, 'reverb'));
        $this->events->dispatch(new ConnectionClosed(42, 0, 'reverb'));

        $this->assertSame(0, $this->clock->calls);
        $this->metricReader->collect();
        $point = $this->sumPoint(self::ACTIVE_CONNECTIONS_METRIC);
        $this->assertSame(1, $point->value);
        $this->assertSame('reverb', $point->attributes->get('hypervel.websocket.server'));
        $this->assertCount(1, $point->attributes);
    }

    /**
     * Create WebSocket instrumentation.
     */
    private function instrumentation(): WebSocketInstrumentation
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new WebSocketInstrumentation(
            $this->events,
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $logContextScopes,
            new OperationOrigin,
        );
    }

    /**
     * Return WebSocket instrumentation options.
     *
     * @return array<string, mixed>
     */
    private function options(
        bool $traces = true,
        bool $duration = true,
        bool $messages = true,
        bool $activeConnections = true,
    ): array {
        return [
            'traces' => $traces,
            'metrics' => [
                self::DURATION_METRIC => $duration,
                self::MESSAGES_METRIC => $messages,
                self::ACTIVE_CONNECTIONS_METRIC => $activeConnections,
            ],
        ];
    }

    /**
     * Create a WebSocket frame.
     */
    private function frame(int $fd = 1, int $opcode = 1, string $data = 'message'): Frame
    {
        $frame = new Frame;
        $frame->fd = $fd;
        $frame->opcode = $opcode;
        $frame->data = $data;

        return $frame;
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

class WebSocketInstrumentationTestClock implements ClockInterface
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
