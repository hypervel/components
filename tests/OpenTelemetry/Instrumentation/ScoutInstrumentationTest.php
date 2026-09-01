<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Database\Eloquent\Model;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\ScoutInstrumentation;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Tests\OpenTelemetry\Fixtures\CapturingAlwaysOffSampler;
use Hypervel\Tests\TestCase;
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
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ScoutInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private EngineOperationRunner $operations;

    private ScoutInstrumentationTestClock $clock;

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
        $this->operations = new EngineOperationRunner;
        $this->clock = new ScoutInstrumentationTestClock;
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
        $this->instrumentation()->register($this->options(traces: false, duration: false));

        $this->assertFalse($this->operations->hasObservers());
        $this->assertSame('result', $this->operations->run(
            $this->operation('search'),
            fn (): string => 'result',
        ));
        $this->assertSame(0, $this->clock->calls);
    }

    #[DataProvider('operationProvider')]
    public function testRecordsEachSupportedEngineOperation(string $operation): void
    {
        $this->registerInstrumentation();
        $descriptor = $this->operation($operation, index: "users_{$operation}");

        $this->clock->timestamp = 1_000_000_000;
        $result = $this->operations->run($descriptor, function (): string {
            $this->assertTrue(Span::getCurrent()->getContext()->isValid());
            $this->clock->timestamp = 3_000_000_000;

            return 'result';
        });

        $this->assertSame('result', $result);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $span = $this->exportedSpan("{$operation} users_{$operation}");
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(3_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('meilisearch', $span->getAttributes()->get(DbAttributes::DB_SYSTEM_NAME));
        $this->assertSame($operation, $span->getAttributes()->get(DbAttributes::DB_OPERATION_NAME));
        $this->assertSame("users_{$operation}", $span->getAttributes()->get(DbAttributes::DB_NAMESPACE));
        $this->assertSame(ScoutInstrumentationTestModel::class, $span->getAttributes()->get('hypervel.scout.model'));

        $this->metricReader->collect();
        $point = $this->histogramPoint();
        $this->assertSame(2, $point->sum);
        $this->assertSame('meilisearch', $point->attributes->get(DbAttributes::DB_SYSTEM_NAME));
        $this->assertSame($operation, $point->attributes->get(DbAttributes::DB_OPERATION_NAME));
        $this->assertSame("users_{$operation}", $point->attributes->get(DbAttributes::DB_NAMESPACE));
        $this->assertNull($point->attributes->get('hypervel.scout.model'));
        $this->assertNull($point->attributes->get(DbAttributes::DB_OPERATION_BATCH_SIZE));
    }

    /**
     * Provide Scout engine operation names.
     *
     * @return iterable<string, array{string}>
     */
    public static function operationProvider(): iterable
    {
        yield 'search' => ['search'];
        yield 'paginate' => ['paginate'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
        yield 'flush' => ['flush'];
        yield 'delete by filter' => ['delete_by_filter'];
    }

    public function testBatchSizeIsTraceOnlyAndEmittedOnlyAboveOne(): void
    {
        $this->registerInstrumentation();

        $this->operations->run($this->operation('update', 'users_many', 3), fn (): null => null);
        $this->operations->run($this->operation('delete', 'users_one', 1), fn (): null => null);
        $this->operations->run($this->operation('flush', 'users_all'), fn (): null => null);

        $this->assertSame(3, $this->exportedSpan('update users_many')
            ->getAttributes()->get(DbAttributes::DB_OPERATION_BATCH_SIZE));
        $this->assertNull($this->exportedSpan('delete users_one')
            ->getAttributes()->get(DbAttributes::DB_OPERATION_BATCH_SIZE));
        $this->assertNull($this->exportedSpan('flush users_all')
            ->getAttributes()->get(DbAttributes::DB_OPERATION_BATCH_SIZE));

        $this->metricReader->collect();

        foreach ($this->histogramPoints() as $point) {
            $this->assertNull($point->attributes->get(DbAttributes::DB_OPERATION_BATCH_SIZE));
        }
    }

    public function testFailureRecordsErrorAndRestoresAmbientContext(): void
    {
        $this->registerInstrumentation();
        $exception = new RuntimeException('Search failed.');
        $ambient = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $ambient->activate();
        $caught = null;

        try {
            try {
                $this->operations->run($this->operation('search'), function () use ($exception): never {
                    throw $exception;
                });
            } catch (Throwable $throwable) {
                $caught = $throwable;
            }

            $this->assertSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        } finally {
            $ambientScope->detach();
            $ambient->end();
        }

        $this->assertSame($exception, $caught);
        $span = $this->exportedSpan('search users');
        $this->assertSame($ambient->getContext()->getSpanId(), $span->getParentSpanId());
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Search failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));

        $this->metricReader->collect();
        $this->assertSame(
            RuntimeException::class,
            $this->histogramPoint()->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
    }

    public function testCancellationProducesNoFalseCompletionTelemetry(): void
    {
        $this->registerInstrumentation();
        $cancellation = new CanceledException;
        $caught = null;
        $debugScopesDisabled = $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'] ?? null;
        $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'] = 'true';

        try {
            // Terminal cancellation deliberately abandons the activated scope under test.
            $this->operations->run($this->operation('search'), function () use ($cancellation): never {
                throw $cancellation;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        } finally {
            if ($debugScopesDisabled === null) {
                unset($_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED']);
            } else {
                $_SERVER['OTEL_PHP_DEBUG_SCOPES_DISABLED'] = $debugScopesDisabled;
            }
        }

        $this->assertSame($cancellation, $caught);
        $this->assertTrue(Span::getCurrent()->getContext()->isValid());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric()->data->dataPoints);
    }

    public function testNestedOperationsUseTheActiveScoutSpanAsParent(): void
    {
        $this->registerInstrumentation(duration: false);

        $this->operations->run($this->operation('paginate', 'users_page'), function (): void {
            $outerSpanId = Span::getCurrent()->getContext()->getSpanId();

            $this->operations->run($this->operation('search', 'users_count'), function () use ($outerSpanId): void {
                $this->assertNotSame($outerSpanId, Span::getCurrent()->getContext()->getSpanId());
            });

            $this->assertSame($outerSpanId, Span::getCurrent()->getContext()->getSpanId());
        });

        $outer = $this->exportedSpan('paginate users_page');
        $inner = $this->exportedSpan('search users_count');
        $this->assertSame($outer->getSpanId(), $inner->getParentSpanId());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testConcurrentOperationsKeepTheirRunnerTokensIsolated(): void
    {
        $this->registerInstrumentation(duration: false);

        $spanIds = parallel([
            function (): string {
                return $this->operations->run($this->operation('search', 'users_a'), function (): string {
                    $spanId = Span::getCurrent()->getContext()->getSpanId();
                    usleep(10_000);
                    $this->assertSame($spanId, Span::getCurrent()->getContext()->getSpanId());

                    return $spanId;
                });
            },
            function (): string {
                return $this->operations->run($this->operation('search', 'users_b'), function (): string {
                    $spanId = Span::getCurrent()->getContext()->getSpanId();
                    usleep(5_000);
                    $this->assertSame($spanId, Span::getCurrent()->getContext()->getSpanId());

                    return $spanId;
                });
            },
        ]);

        $this->assertNotSame($spanIds[0], $spanIds[1]);
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testMetricOnlyModeRecordsWithoutCreatingASpan(): void
    {
        $this->registerInstrumentation(traces: false);

        $this->operations->run($this->operation('search'), function (): void {
            $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        });

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(1, $this->histogramPoints());
    }

    public function testNonRecordingTraceExposesOperationIdentityToTheSamplerAndRecordsTheMetric(): void
    {
        $sampler = new CapturingAlwaysOffSampler;
        $this->tracerProvider->shutdown();
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler($sampler)
            ->addSpanProcessor(new SimpleSpanProcessor($this->spanExporter))
            ->build();
        $this->registerInstrumentation();

        $this->operations->run($this->operation('update', modelCount: 3), function (): void {
            $this->assertTrue(Span::getCurrent()->getContext()->isValid());
            $this->assertFalse(Span::getCurrent()->isRecording());
        });

        $this->assertCount(1, $sampler->samples);
        $attributes = $sampler->samples[0]['attributes'];
        $this->assertSame('meilisearch', $attributes[DbAttributes::DB_SYSTEM_NAME]);
        $this->assertSame('update', $attributes[DbAttributes::DB_OPERATION_NAME]);
        $this->assertSame('users', $attributes[DbAttributes::DB_NAMESPACE]);
        $this->assertSame(ScoutInstrumentationTestModel::class, $attributes['hypervel.scout.model']);
        $this->assertSame(3, $attributes[DbAttributes::DB_OPERATION_BATCH_SIZE]);
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $point = $this->histogramPoint();
        $this->assertNull($point->attributes->get('hypervel.scout.model'));
        $this->assertNull($point->attributes->get(DbAttributes::DB_OPERATION_BATCH_SIZE));
    }

    /**
     * Register Scout instrumentation.
     */
    private function registerInstrumentation(bool $traces = true, bool $duration = true): void
    {
        $this->instrumentation()->register($this->options($traces, $duration));
        $this->assertTrue($this->operations->hasObservers());
    }

    /**
     * Create Scout instrumentation.
     */
    private function instrumentation(): ScoutInstrumentation
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new ScoutInstrumentation(
            $this->operations,
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $logContextScopes,
        );
    }

    /**
     * Return Scout instrumentation options.
     *
     * @return array<string, mixed>
     */
    private function options(bool $traces = true, bool $duration = true): array
    {
        return [
            'traces' => $traces,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => $duration],
        ];
    }

    /**
     * Create a Scout engine operation descriptor.
     */
    private function operation(
        string $operation,
        string $index = 'users',
        ?int $modelCount = null,
    ): EngineOperation {
        return new EngineOperation(
            $operation,
            'meilisearch',
            ScoutInstrumentationTestModel::class,
            $index,
            $modelCount,
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
     * Return the exported Scout duration metric.
     */
    private function metric(): Metric
    {
        foreach ($this->metricExporter->collect() as $metric) {
            if ($metric->name === DbMetrics::DB_CLIENT_OPERATION_DURATION) {
                return $metric;
            }
        }

        $this->fail('The Scout duration metric was not exported.');
    }

    /**
     * Return the Scout duration histogram points.
     *
     * @return list<HistogramDataPoint>
     */
    private function histogramPoints(): array
    {
        $metric = $this->metric();
        $this->assertInstanceOf(Histogram::class, $metric->data);

        return $metric->data->dataPoints;
    }

    /**
     * Return the first Scout duration histogram point.
     */
    private function histogramPoint(): HistogramDataPoint
    {
        $points = $this->histogramPoints();
        $this->assertCount(1, $points);

        return $points[0];
    }
}

class ScoutInstrumentationTestClock implements ClockInterface
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

class ScoutInstrumentationTestModel extends Model
{
}
