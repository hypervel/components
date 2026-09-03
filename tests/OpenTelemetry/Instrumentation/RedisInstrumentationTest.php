<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Closure;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\RedisInstrumentation;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\RedisQueryTextFormatter;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class RedisInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private RedisTestClock $clock;

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
        $this->clock = new RedisTestClock;
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

    public function testRecordsSuccessfulCommandsWithCanonicalOperationAndExactEventTiming(): void
    {
        $this->instrumentation()->register($this->options());
        $connection = $this->connection();
        $ambient = Context::getCurrent();

        $this->events->dispatch(new CommandExecuted(
            'gEt',
            ['customer:1'],
            125.5,
            $connection,
        ));

        $this->assertSame($ambient, Context::getCurrent());
        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame('GET', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertSame(1_874_500_000, $span->getStartEpochNanos());
        $this->assertSame(2_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('redis', $attributes[DbAttributes::DB_SYSTEM_NAME]);
        $this->assertSame('GET', $attributes[DbAttributes::DB_OPERATION_NAME]);
        $this->assertSame('primary', $attributes['hypervel.redis.connection']);
        $this->assertSame('GET customer:1', $attributes[DbAttributes::DB_QUERY_TEXT]);
        $this->assertArrayNotHasKey(DbAttributes::DB_NAMESPACE, $attributes);

        $this->metricReader->collect();
        $duration = $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION);
        $this->assertInstanceOf(Histogram::class, $duration->data);
        $points = $duration->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);
        $this->assertSame(0.1255, $points[0]->sum);
        $this->assertSame('redis', $points[0]->attributes->get(DbAttributes::DB_SYSTEM_NAME));
        $this->assertSame('GET', $points[0]->attributes->get(DbAttributes::DB_OPERATION_NAME));
        $this->assertSame('primary', $points[0]->attributes->get('hypervel.redis.connection'));
        $this->assertNull($points[0]->attributes->get(DbAttributes::DB_QUERY_TEXT));
        $this->assertNull($points[0]->attributes->get(DbAttributes::DB_NAMESPACE));
    }

    public function testLogicalConnectionsRemainDistinctMetricSeries(): void
    {
        $this->instrumentation(resolverCalls: 0)->register($this->options([
            'traces' => false,
        ]));

        $this->events->dispatch(new CommandExecuted('GET', ['customer:1'], 10, $this->connection('cache')));
        $this->events->dispatch(new CommandExecuted('GET', ['job:1'], 20, $this->connection('queue')));

        $this->metricReader->collect();
        $points = $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(2, $points);
        $connections = array_map(
            static fn ($point): mixed => $point->attributes->get('hypervel.redis.connection'),
            $points,
        );
        sort($connections);

        $this->assertSame(['cache', 'queue'], $connections);
    }

    public function testRecordsFailedCommandsWithErrorAndExactSpanHandoff(): void
    {
        $this->exceptionContexts->enable();
        $this->instrumentation()->register($this->options());
        $connection = $this->connection();
        $exception = new RuntimeException('connection lost');
        $scope = $this->origins
            ->withOrigin(Context::getCurrent(), OperationOrigin::REQUEST)
            ->activate();

        try {
            $this->events->dispatch(new CommandFailed(
                'set',
                ['customer:1', 'private-value'],
                $exception,
                $connection,
                50,
            ));
        } finally {
            $scope->detach();
        }

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];
        $handoff = $this->exceptionContexts->take($exception);

        $this->assertNotNull($handoff);
        $this->assertSame(
            $span->getSpanId(),
            Span::fromContext($handoff->context)->getContext()->getSpanId(),
        );
        $this->assertSame(OperationOrigin::REQUEST, $handoff->origin);
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('SET customer:1', $span->getAttributes()->get(DbAttributes::DB_QUERY_TEXT));
        $this->assertCount(1, $span->getEvents());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
        $this->assertSame(
            RuntimeException::class,
            $span->getEvents()[0]->getAttributes()->get(ExceptionAttributes::EXCEPTION_TYPE),
        );

        $this->metricReader->collect();
        $point = $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints[0];
        $this->assertSame(0.05, $point->sum);
        $this->assertSame(RuntimeException::class, $point->attributes->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testCustomResolverReceivesOriginalEventDataAndReplacesTheFormatter(): void
    {
        $parameters = ['customer:1', 'private-value'];
        $formatter = new RedisQueryTextFormatterProbe;
        $resolver = function (string $command, array $received, string $connection) use ($parameters): string {
            $this->assertSame('sEt', $command);
            $this->assertSame($parameters, $received);
            $this->assertSame('cache', $connection);

            return 'CUSTOM 😀 TEXT';
        };
        $this->instrumentation(formatter: $formatter, resolver: $resolver)
            ->register($this->options(['query_text_max_length' => 8]));

        $this->events->dispatch(new CommandExecuted(
            'sEt',
            $parameters,
            1,
            $this->connection('cache'),
        ));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('CUSTOM 😀', $spans[0]->getAttributes()->get(DbAttributes::DB_QUERY_TEXT));
        $this->assertSame(0, $formatter->calls);
    }

    public function testResolverFailureIsIsolatedFromCommandTelemetry(): void
    {
        $resolver = static function (): never {
            throw new RuntimeException('resolver failed');
        };
        $this->instrumentation(resolver: $resolver)->register($this->options());

        $this->events->dispatch(new CommandExecuted('GET', ['customer:1'], 1, $this->connection()));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertNull($spans[0]->getAttributes()->get(DbAttributes::DB_QUERY_TEXT));
        $this->metricReader->collect();
        $this->assertCount(1, $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints);
    }

    public function testResolverCancellationPropagatesWithoutCompletionTelemetry(): void
    {
        $cancellation = new CanceledException;
        $resolver = static function () use ($cancellation): never {
            throw $cancellation;
        };
        $this->instrumentation(resolver: $resolver)->register($this->options());

        try {
            $this->events->dispatch(new CommandExecuted('GET', ['customer:1'], 1, $this->connection()));
            $this->fail('Expected the resolver cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertCount(0, $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints);
    }

    public function testQueryTextCanBeDisabledWithoutFormatterOrResolverWork(): void
    {
        $formatter = new RedisQueryTextFormatterProbe;
        $this->instrumentation($formatter, resolverCalls: 0)->register($this->options([
            'query_text' => false,
        ]));

        $this->events->dispatch(new CommandExecuted('GET', ['customer:1'], 1, $this->connection()));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertNull($spans[0]->getAttributes()->get(DbAttributes::DB_QUERY_TEXT));
        $this->assertSame(0, $formatter->calls);
    }

    public function testMetricsOnlyModeSkipsEveryTraceOnlyDetail(): void
    {
        $formatter = new RedisQueryTextFormatterProbe;
        $this->instrumentation($formatter, resolverCalls: 0)->register($this->options([
            'traces' => false,
        ]));

        $this->events->dispatch(new CommandExecuted('gEt', ['customer:1'], 20, $this->connection()));

        $this->assertSame(0, $formatter->calls);
        $this->assertSame(0, $this->clock->calls);
        $this->assertCount(0, $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $point = $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints[0];
        $this->assertSame('GET', $point->attributes->get(DbAttributes::DB_OPERATION_NAME));
    }

    public function testNonRecordingSpansSkipEveryQueryTextDependency(): void
    {
        $tracerProvider = TracerProvider::builder()
            ->setSampler(new AlwaysOffSampler)
            ->build();
        $formatter = new RedisQueryTextFormatterProbe;

        try {
            $this->instrumentation($formatter, $tracerProvider, resolverCalls: 0)
                ->register($this->options([
                    'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
                ]));

            $this->events->dispatch(new CommandExecuted('GET', ['customer:1'], 20, $this->connection()));

            $this->assertSame(0, $formatter->calls);
            $this->assertCount(0, $this->spanExporter->getSpans());
        } finally {
            $tracerProvider->shutdown();
        }
    }

    public function testAllOutputsOffRegistersNoRedisListeners(): void
    {
        $this->instrumentation(resolverCalls: 0, enableEventCalls: 0)->register($this->options([
            'traces' => false,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
        ]));

        $this->assertFalse($this->events->hasListeners(CommandExecuted::class));
        $this->assertFalse($this->events->hasListeners(CommandFailed::class));
    }

    /**
     * Create the instrumentation under test.
     */
    private function instrumentation(
        ?RedisQueryTextFormatter $formatter = null,
        ?TracerProviderInterface $tracerProvider = null,
        ?Closure $resolver = null,
        int $resolverCalls = 1,
        int $enableEventCalls = 1,
    ): RedisInstrumentation {
        $openTelemetryManager = m::mock(OpenTelemetryManager::class);
        $expectation = $openTelemetryManager->shouldReceive('redisQueryTextResolver');

        if ($resolverCalls === 0) {
            $expectation->never();
        } else {
            $expectation->times($resolverCalls)->andReturn($resolver);
        }

        $redisManager = m::mock(RedisManager::class);
        $redisManager->shouldReceive('enableEvents')->times($enableEventCalls);

        return new RedisInstrumentation(
            $this->events,
            $tracerProvider ?? $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $openTelemetryManager,
            $redisManager,
            $formatter ?? new RedisQueryTextFormatter,
            $this->exceptionContexts,
            $this->origins,
            ProcessIdentity::eventWorker(0),
        );
    }

    /**
     * Create a Redis connection mock.
     */
    private function connection(string $name = 'primary'): RedisConnection
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getName')->once()->andReturn($name);

        return $connection;
    }

    /**
     * Return normalized Redis options.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_replace([
            'traces' => true,
            'query_text' => true,
            'query_text_max_length' => null,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => true],
        ], $overrides);
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
}

class RedisQueryTextFormatterProbe extends RedisQueryTextFormatter
{
    public int $calls = 0;

    /**
     * Format a canonical Redis command without exposing value arguments.
     */
    public function format(string $command, array $parameters): string
    {
        ++$this->calls;

        return parent::format($command, $parameters);
    }
}

class RedisTestClock implements ClockInterface
{
    public int $calls = 0;

    /**
     * Return a deterministic timestamp.
     */
    public function now(): int
    {
        ++$this->calls;

        return 2_000_000_000;
    }
}
