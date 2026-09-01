<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Grpc\ClientGrpcOperation;
use Hypervel\Grpc\GrpcOperation;
use Hypervel\Grpc\GrpcOperationResult;
use Hypervel\Grpc\GrpcOperationRunner;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Grpc\ServerGrpcOperation;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Context\GrpcMetadataSetter;
use Hypervel\OpenTelemetry\Instrumentation\GrpcInstrumentation;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode as TraceStatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
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
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class GrpcInstrumentationTest extends TestCase
{
    private const string CLIENT_DURATION_METRIC = 'rpc.client.call.duration';

    private const string SERVER_DURATION_METRIC = 'rpc.server.call.duration';

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private GrpcOperationRunner $operations;

    private GrpcInstrumentationTestClock $clock;

    private ExceptionContextRegistry $exceptionContexts;

    private OperationOrigin $origins;

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
        $this->operations = new GrpcOperationRunner;
        $this->clock = new GrpcInstrumentationTestClock;
        $this->exceptionContexts = new ExceptionContextRegistry;
        $this->exceptionContexts->enable();
        $this->origins = new OperationOrigin;
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

    public function testRegistersNoObserverWhenEveryOutputIsDisabled(): void
    {
        $this->instrumentation()->register($this->options(false, false, false));

        $this->assertFalse($this->operations->hasObservers());
        $this->assertSame(0, $this->clock->calls);
    }

    public function testRejectsAnUnknownPublicOperationTypeClearly(): void
    {
        $this->registerInstrumentation();
        $operation = new class implements GrpcOperation {
            public function serviceMethod(): null
            {
                return null;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsupported gRPC operation type [');

        $this->operations->start($operation);
    }

    public function testClientCallInjectsItsExplicitContextWithoutActivatingIt(): void
    {
        $this->registerInstrumentation();
        $ambient = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $ambient->activate();
        $operation = $this->clientOperation(Metadata::make([
            'traceparent' => ['old', 'duplicate'],
            'application' => 'preserved',
        ]));

        try {
            $this->clock->timestamp = 1_000_000_000;
            $handle = $this->operations->start($operation);
            $this->assertSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
            $this->assertSame(['preserved'], $operation->metadata()->values('application'));
            $this->assertCount(1, $operation->metadata()->values('traceparent'));
            $traceparent = $operation->metadata()->first('traceparent');
            $this->assertIsString($traceparent);

            $this->clock->timestamp = 4_000_000_000;
            $handle->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 2));
            $this->assertSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        } finally {
            $ambientScope->detach();
            $ambient->end();
        }

        $span = $this->exportedSpan('example.Echo/Say');
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame($ambient->getContext()->getSpanId(), $span->getParentSpanId());
        $this->assertSame($span->getTraceId(), explode('-', $traceparent)[1]);
        $this->assertSame($span->getSpanId(), explode('-', $traceparent)[2]);
        $this->assertSame('grpc', $span->getAttributes()->get('rpc.system.name'));
        $this->assertSame('example.Echo/Say', $span->getAttributes()->get('rpc.method'));
        $this->assertSame('OK', $span->getAttributes()->get('rpc.response.status_code'));
        $this->assertSame('grpc.test', $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(50051, $span->getAttributes()->get(ServerAttributes::SERVER_PORT));
        $this->assertSame(2, $span->getAttributes()->get('hypervel.grpc.attempt_count'));

        $this->metricReader->collect();
        $point = $this->histogramPoint(self::CLIENT_DURATION_METRIC);
        $this->assertSame(3, $point->sum);
        $this->assertSame('OK', $point->attributes->get('rpc.response.status_code'));
        $this->assertNull($point->attributes->get('hypervel.grpc.attempt_count'));
    }

    public function testServerCallExtractsRemoteParentAndActivatesItsContext(): void
    {
        $this->registerInstrumentation();
        $operation = $this->serverOperation(
            metadata: [
                'TraceParent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                'authorization' => 'secret',
            ],
        );

        $handle = $this->operations->start($operation);
        $serverSpanId = Span::getCurrent()->getContext()->getSpanId();
        $this->assertSame(OperationOrigin::RPC, $this->origins->resolve(Context::getCurrent()));
        $child = $this->tracerProvider->getTracer('test')->spanBuilder('child')->startSpan();
        $child->end();
        $handle->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 1));

        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $server = $this->exportedSpan('example.Echo/Say');
        $this->assertSame(SpanKind::KIND_SERVER, $server->getKind());
        $this->assertSame('00f067aa0ba902b7', $server->getParentSpanId());
        $this->assertSame(50051, $server->getAttributes()->get(ServerAttributes::SERVER_PORT));
        $this->assertNull($server->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame($serverSpanId, $this->exportedSpan('child')->getParentSpanId());
        $this->assertNotContains('secret', $server->getAttributes()->toArray());

        $this->metricReader->collect();
        $point = $this->histogramPoint(self::SERVER_DURATION_METRIC);
        $this->assertNull($point->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertNull($point->attributes->get(ServerAttributes::SERVER_PORT));
    }

    public function testMalformedServerPathUsesBoundedFallbackWithoutCapturingRawInput(): void
    {
        $this->registerInstrumentation();
        $operation = $this->serverOperation(path: '/example.Echo/Say?token=secret');

        $this->operations->start($operation)->finish(
            new GrpcOperationResult(new Status(StatusCode::Ok), null, 1),
        );

        $span = $this->exportedSpan('grpc');
        $this->assertSame('_OTHER', $span->getAttributes()->get('rpc.method'));
        $this->assertNotContains($operation->path, $span->getAttributes()->toArray());
        $this->assertNotContains('secret', $span->getAttributes()->toArray());

        $this->metricReader->collect();
        $point = $this->histogramPoint(self::SERVER_DURATION_METRIC);
        $this->assertSame('_OTHER', $point->attributes->get('rpc.method'));
    }

    public function testServerMetricAddressIsOptInAndOmitsWildcardAddress(): void
    {
        $this->registerInstrumentation(traces: false, clientDuration: false, serverMetricAddress: true);

        $this->operations->start($this->serverOperation(address: '127.0.0.1'))->finish(
            new GrpcOperationResult(new Status(StatusCode::Ok), null, 1),
        );
        $this->operations->start($this->serverOperation(
            path: '/example.Echo/Wildcard',
            address: '0.0.0.0',
        ))->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 1));

        $this->metricReader->collect();
        $points = $this->histogramPoints(self::SERVER_DURATION_METRIC);
        $this->assertCount(2, $points);
        $pointsByMethod = [];

        foreach ($points as $point) {
            $pointsByMethod[$point->attributes->get('rpc.method')] = $point;
        }

        $this->assertSame('127.0.0.1', $pointsByMethod['example.Echo/Say']
            ->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(50051, $pointsByMethod['example.Echo/Say']
            ->attributes->get(ServerAttributes::SERVER_PORT));
        $this->assertNull($pointsByMethod['example.Echo/Wildcard']
            ->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(50051, $pointsByMethod['example.Echo/Wildcard']
            ->attributes->get(ServerAttributes::SERVER_PORT));
    }

    public function testClientTreatsEveryNonOkStatusAsAnError(): void
    {
        $this->registerInstrumentation();

        $this->operations->start($this->clientOperation())->finish(
            new GrpcOperationResult(new Status(StatusCode::InvalidArgument), null, 1),
        );

        $span = $this->exportedSpan('example.Echo/Say');
        $this->assertSame(TraceStatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('INVALID_ARGUMENT', $span->getAttributes()->get('rpc.response.status_code'));
        $this->assertSame('INVALID_ARGUMENT', $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));

        $this->metricReader->collect();
        $this->assertSame(
            'INVALID_ARGUMENT',
            $this->histogramPoint(self::CLIENT_DURATION_METRIC)
                ->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
    }

    public function testServerUsesTheConventionErrorStatusSet(): void
    {
        $this->registerInstrumentation(clientDuration: false);
        $applicationException = new RuntimeException('Invalid application input.');

        $this->operations->start($this->serverOperation(path: '/example.Echo/Rejected'))->finish(
            new GrpcOperationResult(new Status(StatusCode::InvalidArgument), $applicationException, 1),
        );
        $this->operations->start($this->serverOperation(path: '/example.Echo/Failed'))->finish(
            new GrpcOperationResult(new Status(StatusCode::Unknown), null, 1),
        );

        $rejected = $this->exportedSpan('example.Echo/Rejected');
        $failed = $this->exportedSpan('example.Echo/Failed');
        $this->assertSame(TraceStatusCode::STATUS_UNSET, $rejected->getStatus()->getCode());
        $this->assertNull($rejected->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Invalid application input.', $rejected->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $this->assertSame(TraceStatusCode::STATUS_ERROR, $failed->getStatus()->getCode());
        $this->assertSame('UNKNOWN', $failed->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testClientTransportFailureRecordsExceptionAndRetainsItsEndedContext(): void
    {
        $this->registerInstrumentation();
        $exception = new RuntimeException('Transport failed.');

        $this->operations->start($this->clientOperation())->finish(
            new GrpcOperationResult(null, $exception, 1),
        );

        $span = $this->exportedSpan('example.Echo/Say');
        $this->assertSame(TraceStatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Transport failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $exceptionContext = $this->exceptionContexts->take($exception);
        $this->assertNotNull($exceptionContext);
        $this->assertSame($span->getSpanId(), Span::fromContext($exceptionContext->context)
            ->getContext()->getSpanId());
    }

    public function testClientPropagationFailureClosesItsStartedTelemetryBeforeRethrow(): void
    {
        $exception = new RuntimeException('Propagation failed.');
        $this->registerInstrumentation(propagator: new GrpcInstrumentationFailingPropagator($exception));
        $caught = null;

        try {
            $this->operations->start($this->clientOperation());
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($exception, $caught);
        $span = $this->exportedSpan('example.Echo/Say');
        $this->assertSame(TraceStatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(0, $span->getAttributes()->get('hypervel.grpc.attempt_count'));
        $this->assertNotNull($this->exceptionContexts->take($exception));
        $this->metricReader->collect();
        $this->assertSame(
            RuntimeException::class,
            $this->histogramPoint(self::CLIENT_DURATION_METRIC)
                ->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
    }

    public function testRuntimeCancellationProducesNoFalseCompletionTelemetry(): void
    {
        $this->registerInstrumentation();
        $cancellation = new CanceledException;
        $handle = $this->operations->start($this->serverOperation());
        $activeSpanId = Span::getCurrent()->getContext()->getSpanId();

        $handle->finish(new GrpcOperationResult(null, $cancellation, 0));

        $this->assertSame($activeSpanId, Span::getCurrent()->getContext()->getSpanId());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::SERVER_DURATION_METRIC)->data->dataPoints);
    }

    public function testMetricOnlyClientOutputDoesNoWorkForServerCalls(): void
    {
        $this->registerInstrumentation(traces: false, serverDuration: false);

        $this->operations->start($this->serverOperation())->finish(
            new GrpcOperationResult(new Status(StatusCode::Ok), null, 1),
        );
        $this->assertSame(0, $this->clock->calls);

        $this->operations->start($this->clientOperation())->finish(
            new GrpcOperationResult(new Status(StatusCode::Ok), null, 1),
        );
        $this->assertSame(2, $this->clock->calls);
        $this->metricReader->collect();
        $this->assertCount(1, $this->histogramPoints(self::CLIENT_DURATION_METRIC));
        $this->assertNull($this->metricOrNull(self::SERVER_DURATION_METRIC));
    }

    public function testMetricOnlyServerOutputDoesNoWorkForClientCalls(): void
    {
        $this->registerInstrumentation(traces: false, clientDuration: false);

        $this->operations->start($this->clientOperation())->finish(
            new GrpcOperationResult(new Status(StatusCode::Ok), null, 1),
        );
        $this->assertSame(0, $this->clock->calls);

        $this->operations->start($this->serverOperation())->finish(
            new GrpcOperationResult(new Status(StatusCode::Ok), null, 1),
        );
        $this->assertSame(2, $this->clock->calls);
        $this->metricReader->collect();
        $this->assertCount(1, $this->histogramPoints(self::SERVER_DURATION_METRIC));
        $this->assertNull($this->metricOrNull(self::CLIENT_DURATION_METRIC));
    }

    public function testNestedAndConcurrentServerCallsKeepObserverTokensIsolated(): void
    {
        $this->registerInstrumentation(clientDuration: false, serverDuration: false);

        $outer = $this->operations->start($this->serverOperation(path: '/example.Echo/Outer'));
        $outerSpanId = Span::getCurrent()->getContext()->getSpanId();
        $inner = $this->operations->start($this->serverOperation(path: '/example.Echo/Inner'));
        $inner->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 1));
        $this->assertSame($outerSpanId, Span::getCurrent()->getContext()->getSpanId());

        $spanIds = parallel([
            function (): string {
                $handle = $this->operations->start($this->serverOperation(path: '/example.Echo/First'));
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(10_000);
                $handle->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 1));

                return $spanId;
            },
            function (): string {
                $handle = $this->operations->start($this->serverOperation(path: '/example.Echo/Second'));
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(5_000);
                $handle->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 1));

                return $spanId;
            },
        ]);

        $this->assertNotSame($spanIds[0], $spanIds[1]);
        $this->assertSame($outerSpanId, Span::getCurrent()->getContext()->getSpanId());
        $outer->finish(new GrpcOperationResult(new Status(StatusCode::Ok), null, 1));
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    /**
     * Register gRPC instrumentation.
     */
    private function registerInstrumentation(
        bool $traces = true,
        bool $clientDuration = true,
        bool $serverDuration = true,
        bool $serverMetricAddress = false,
        ?TextMapPropagatorInterface $propagator = null,
    ): void {
        $this->instrumentation($propagator)->register($this->options(
            $traces,
            $clientDuration,
            $serverDuration,
            $serverMetricAddress,
        ));
        $this->assertTrue($this->operations->hasObservers());
    }

    /**
     * Create gRPC instrumentation.
     */
    private function instrumentation(?TextMapPropagatorInterface $propagator = null): GrpcInstrumentation
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new GrpcInstrumentation(
            $this->operations,
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $propagator ?? TraceContextPropagator::getInstance(),
            new GrpcMetadataSetter,
            $logContextScopes,
            $this->exceptionContexts,
            $this->origins,
            ProcessIdentity::eventWorker(1),
        );
    }

    /**
     * Return gRPC instrumentation options.
     *
     * @return array<string, mixed>
     */
    private function options(
        bool $traces = true,
        bool $clientDuration = true,
        bool $serverDuration = true,
        bool $serverMetricAddress = false,
    ): array {
        return [
            'traces' => $traces,
            'server_metric_address' => $serverMetricAddress,
            'metrics' => [
                self::CLIENT_DURATION_METRIC => $clientDuration,
                self::SERVER_DURATION_METRIC => $serverDuration,
            ],
        ];
    }

    /**
     * Create a client operation descriptor.
     */
    private function clientOperation(?Metadata $metadata = null): ClientGrpcOperation
    {
        return new ClientGrpcOperation(
            ServiceMethod::from('example.Echo', 'Say'),
            'grpc.test',
            50051,
            $metadata ?? Metadata::make(),
        );
    }

    /**
     * Create a server operation descriptor.
     *
     * @param array<array-key, list<string>|string> $metadata
     */
    private function serverOperation(
        string $path = '/example.Echo/Say',
        array $metadata = [],
        string $address = '0.0.0.0',
    ): ServerGrpcOperation {
        return new ServerGrpcOperation(
            'POST',
            $path,
            $metadata,
            'grpc',
            $address,
            50051,
        );
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
     * Return an exported metric when present.
     */
    private function metricOrNull(string $name): ?Metric
    {
        foreach ($this->metricExporter->collect() as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * Return an exported metric by name.
     */
    private function metric(string $name): Metric
    {
        return $this->metricOrNull($name)
            ?? $this->fail("Metric [{$name}] was not exported.");
    }

    /**
     * Return histogram points for an exported metric.
     *
     * @return list<HistogramDataPoint>
     */
    private function histogramPoints(string $name): array
    {
        $metric = $this->metric($name);
        $this->assertInstanceOf(Histogram::class, $metric->data);

        return $metric->data->dataPoints;
    }

    /**
     * Return the first histogram point for an exported metric.
     */
    private function histogramPoint(string $name): HistogramDataPoint
    {
        $points = $this->histogramPoints($name);
        $this->assertCount(1, $points);

        return $points[0];
    }
}

class GrpcInstrumentationTestClock implements ClockInterface
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

class GrpcInstrumentationFailingPropagator implements TextMapPropagatorInterface
{
    public function __construct(protected Throwable $exception)
    {
    }

    /**
     * Return propagation fields.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Fail propagation injection.
     */
    public function inject(
        mixed &$carrier,
        ?PropagationSetterInterface $setter = null,
        ?ContextInterface $context = null,
    ): void {
        throw $this->exception;
    }

    /**
     * Return the supplied extraction context.
     */
    public function extract(
        mixed $carrier,
        ?PropagationGetterInterface $getter = null,
        ?ContextInterface $context = null,
    ): ContextInterface {
        return $context ?? Context::getCurrent();
    }
}
