<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Closure;
use Hypervel\Cache\Events\CacheFailedOver;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheLocksFlushed;
use Hypervel\Cache\Events\CacheLocksFlushFailed;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyRetrievalFailed;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\ManyKeysRetrievalFailed;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\CacheInstrumentation;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class CacheInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

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

    public function testRecordsEveryCompletionOutcomeWithTruthfulTraceAndMetricAttributes(): void
    {
        $this->instrumentation(resolverCalls: 7)->register($this->options(['key' => true]));
        $exception = new RuntimeException('cache unavailable');

        $this->withinSpan(function () use ($exception): void {
            $this->events->dispatch(new CacheHit('array', 'customer:1', 'value'));
            $this->events->dispatch(new CacheMissed('array', 'customer:2'));
            $this->events->dispatch(new KeyRetrievalFailed('array', 'customer:3', $exception));
            $this->events->dispatch(new ManyKeysRetrievalFailed('array', ['a', 'b', 'c'], $exception));
            $this->events->dispatch(new KeyWritten('array', 'customer:4', 'value', 60));
            $this->events->dispatch(new KeyWriteFailed('array', 'customer:5', 'value', 30));
            $this->events->dispatch(new KeyForgotten('array', 'customer:6'));
            $this->events->dispatch(new KeyForgetFailed('array', 'customer:7'));
            $this->events->dispatch(new CacheFlushed('array'));
            $this->events->dispatch(new CacheFlushFailed('array', exception: $exception));
            $this->events->dispatch(new CacheLocksFlushed('array'));
            $this->events->dispatch(new CacheLocksFlushFailed('array'));
            $this->events->dispatch(new CacheFailedOver('redis', $exception, 'resilient'));
        });

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $events = $spans[0]->getEvents();
        $this->assertSame([
            'hypervel.cache.get',
            'hypervel.cache.get',
            'hypervel.cache.get',
            'hypervel.cache.get',
            'hypervel.cache.put',
            'hypervel.cache.put',
            'hypervel.cache.forget',
            'hypervel.cache.forget',
            'hypervel.cache.flush',
            'hypervel.cache.flush',
            'hypervel.cache.lock_flush',
            'hypervel.cache.lock_flush',
            'hypervel.cache.failover',
        ], array_map(static fn ($event): string => $event->getName(), $events));
        $this->assertSame('customer:1', $events[0]->getAttributes()->get('hypervel.cache.key'));
        $this->assertSame('hit', $events[0]->getAttributes()->get('result'));
        $this->assertSame(RuntimeException::class, $events[2]->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertNull($events[3]->getAttributes()->get('hypervel.cache.key'));
        $this->assertSame(60, $events[4]->getAttributes()->get('hypervel.cache.ttl'));
        $this->assertSame(30, $events[5]->getAttributes()->get('hypervel.cache.ttl'));
        $this->assertSame('resilient', $events[12]->getAttributes()->get('hypervel.cache.store'));
        $this->assertSame('redis', $events[12]->getAttributes()->get('hypervel.cache.failed_store'));

        $metric = $this->metric('hypervel.cache.operations');
        $this->assertPoint($metric, 1, [
            'hypervel.cache.operation' => 'get',
            'hypervel.cache.store' => 'array',
            'result' => 'hit',
        ]);
        $this->assertPoint($metric, 4, [
            'hypervel.cache.operation' => 'get',
            'hypervel.cache.store' => 'array',
            'result' => 'failure',
            ErrorAttributes::ERROR_TYPE => RuntimeException::class,
        ]);
        $put = $this->point($metric, [
            'hypervel.cache.operation' => 'put',
            'hypervel.cache.store' => 'array',
            'result' => 'success',
        ]);
        $this->assertSame(1, $put->value);
        $this->assertNull($put->attributes->get('hypervel.cache.key'));
        $this->assertNull($put->attributes->get('hypervel.cache.ttl'));
        $this->assertPoint($metric, 1, [
            'hypervel.cache.operation' => 'failover',
            'hypervel.cache.store' => 'resilient',
            'hypervel.cache.failed_store' => 'redis',
            'result' => 'failure',
            ErrorAttributes::ERROR_TYPE => RuntimeException::class,
        ]);
    }

    public function testCustomKeyResolverReceivesNormalizedKeyAndStoreBeforeUnicodeCapping(): void
    {
        $resolver = function (string $key, ?string $store): string {
            $this->assertSame('customer:1', $key);
            $this->assertSame('array', $store);

            return 'CUSTOM 😀 TEXT';
        };
        $this->instrumentation($resolver)->register($this->options([
            'key' => true,
            'key_max_length' => 8,
            'metrics' => ['hypervel.cache.operations' => false],
        ]));

        $this->withinSpan(function (): void {
            $this->events->dispatch(new CacheHit('array', CacheInstrumentationKey::CUSTOMER, 'value'));
        });

        $events = $this->spanExporter->getSpans()[0]->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('CUSTOM 😀', $events[0]->getAttributes()->get('hypervel.cache.key'));
    }

    public function testResolverFailureOmitsTheKeyWithoutSuppressingCompletionTelemetry(): void
    {
        $resolver = static function (): never {
            throw new RuntimeException('resolver failed');
        };
        $this->instrumentation($resolver)->register($this->options(['key' => true]));

        $this->withinSpan(function (): void {
            $this->events->dispatch(new CacheHit('array', 'customer:1', 'value'));
        });

        $events = $this->spanExporter->getSpans()[0]->getEvents();
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->getAttributes()->get('hypervel.cache.key'));
        $this->assertPoint($this->metric('hypervel.cache.operations'), 1, [
            'hypervel.cache.operation' => 'get',
            'result' => 'hit',
        ]);
    }

    public function testResolverCancellationPropagatesWithoutCompletionTelemetry(): void
    {
        $cancellation = new CanceledException;
        $resolver = static function () use ($cancellation): never {
            throw $cancellation;
        };
        $this->instrumentation($resolver)->register($this->options(['key' => true]));

        $this->withinSpan(function () use ($cancellation): void {
            try {
                $this->events->dispatch(new CacheHit('array', 'customer:1', 'value'));
                $this->fail('Expected the resolver cancellation to propagate.');
            } catch (CanceledException $exception) {
                $this->assertSame($cancellation, $exception);
            }
        });

        $this->assertCount(0, $this->spanExporter->getSpans()[0]->getEvents());
        $metric = $this->metric('hypervel.cache.operations');
        $this->assertInstanceOf(Sum::class, $metric->data);
        $this->assertCount(0, $metric->data->dataPoints);
    }

    public function testMetricsOnlyModeSkipsCurrentSpanAndEveryKeyDependency(): void
    {
        $this->instrumentation(resolverCalls: 0)->register($this->options([
            'traces' => false,
            'key' => true,
        ]));

        $this->withinSpan(function (): void {
            $this->events->dispatch(new CacheHit('array', 'customer:1', 'value'));
        });

        $this->assertCount(0, $this->spanExporter->getSpans()[0]->getEvents());
        $this->assertPoint($this->metric('hypervel.cache.operations'), 1, [
            'hypervel.cache.operation' => 'get',
            'result' => 'hit',
        ]);
    }

    public function testNonRecordingSpanSkipsEveryKeyDependencyWhileMetricsRemainActive(): void
    {
        $tracerProvider = TracerProvider::builder()
            ->setSampler(new AlwaysOffSampler)
            ->build();

        try {
            $this->instrumentation(tracerProvider: $tracerProvider, resolverCalls: 0)
                ->register($this->options(['key' => true]));
            $span = $tracerProvider->getTracer('test')->spanBuilder('request')->startSpan();
            $scope = $span->activate();

            try {
                $this->events->dispatch(new CacheHit('array', 'customer:1', 'value'));
            } finally {
                $scope->detach();
                $span->end();
            }

            $this->assertPoint($this->metric('hypervel.cache.operations'), 1, [
                'hypervel.cache.operation' => 'get',
                'result' => 'hit',
            ]);
        } finally {
            $tracerProvider->shutdown();
        }
    }

    public function testAllOutputsOffRegistersNoCacheListeners(): void
    {
        $this->instrumentation(resolverCalls: 0)->register($this->options([
            'traces' => false,
            'metrics' => ['hypervel.cache.operations' => false],
        ]));

        foreach ([
            CacheHit::class,
            CacheMissed::class,
            KeyRetrievalFailed::class,
            ManyKeysRetrievalFailed::class,
            KeyWritten::class,
            KeyWriteFailed::class,
            KeyForgotten::class,
            KeyForgetFailed::class,
            CacheFlushed::class,
            CacheFlushFailed::class,
            CacheLocksFlushed::class,
            CacheLocksFlushFailed::class,
            CacheFailedOver::class,
        ] as $event) {
            $this->assertFalse($this->events->hasListeners($event));
        }
    }

    /**
     * Create the instrumentation under test.
     */
    private function instrumentation(
        ?Closure $resolver = null,
        ?TracerProviderInterface $tracerProvider = null,
        int $resolverCalls = 1,
    ): CacheInstrumentation {
        $openTelemetryManager = m::mock(OpenTelemetryManager::class);
        $expectation = $openTelemetryManager->shouldReceive('cacheKeyResolver');

        if ($resolverCalls === 0) {
            $expectation->never();
        } else {
            $expectation->times($resolverCalls)->andReturn($resolver);
        }

        return new CacheInstrumentation(
            $this->events,
            $this->meterProvider,
            $openTelemetryManager,
        );
    }

    /**
     * Run a callback under one recording span.
     */
    private function withinSpan(Closure $callback): void
    {
        $span = $this->tracerProvider->getTracer('test')->spanBuilder('request')->startSpan();
        $scope = $span->activate();

        try {
            $callback();
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Return normalized cache options.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_replace([
            'traces' => true,
            'key' => false,
            'key_max_length' => 500,
            'metrics' => ['hypervel.cache.operations' => true],
        ], $overrides);
    }

    /**
     * Return one exported metric by name.
     */
    private function metric(string $name): Metric
    {
        $this->metricReader->collect();

        foreach ($this->metricExporter->collect(true) as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        $this->fail("Metric [{$name}] was not exported.");
    }

    /**
     * Assert one exact numeric point exists.
     *
     * @param array<string, string> $attributes
     */
    private function assertPoint(Metric $metric, int $value, array $attributes): void
    {
        $point = $this->point($metric, $attributes);

        $this->assertSame($value, $point->value);
    }

    /**
     * Return one numeric point with all expected attributes.
     *
     * @param array<string, string> $attributes
     */
    private function point(Metric $metric, array $attributes): NumberDataPoint
    {
        $this->assertInstanceOf(Sum::class, $metric->data);

        foreach ($metric->data->dataPoints as $point) {
            foreach ($attributes as $name => $value) {
                if ($point->attributes->get($name) !== $value) {
                    continue 2;
                }
            }

            return $point;
        }

        $this->fail('The expected metric point was not exported.');
    }
}

enum CacheInstrumentationKey: string
{
    case CUSTOMER = 'customer:1';
}
