<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\QueryFailed;
use Hypervel\Database\QueryException;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\DatabaseInstrumentation;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
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
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use PDOException;
use RuntimeException;

class DatabaseInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private DatabaseTestClock $clock;

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
        $this->clock = new DatabaseTestClock;
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

    public function testRecordsSuccessfulQueriesWithExactEventTimingAndControlledAttributes(): void
    {
        $this->instrumentation()->register($this->options());
        $connection = $this->connection(
            'pgsql',
            'application',
            ['host' => 'database.test', 'port' => 5432],
        );
        $query = ' SELECT * FROM users WHERE email = ?; ';
        $ambient = Context::getCurrent();

        $this->events->dispatch(new QueryExecuted(
            $query,
            ['person@example.test'],
            125.5,
            $connection,
            'write',
        ));

        $this->assertSame($ambient, Context::getCurrent());
        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $span = $spans[0];
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame('SELECT application', $span->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $span->getKind());
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertSame(1_874_500_000, $span->getStartEpochNanos());
        $this->assertSame(2_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('postgresql', $attributes[DbAttributes::DB_SYSTEM_NAME]);
        $this->assertSame('application', $attributes[DbAttributes::DB_NAMESPACE]);
        $this->assertSame('SELECT', $attributes[DbAttributes::DB_OPERATION_NAME]);
        $this->assertSame($query, $attributes[DbAttributes::DB_QUERY_TEXT]);
        $this->assertSame('database.test', $attributes[ServerAttributes::SERVER_ADDRESS]);
        $this->assertSame(5432, $attributes[ServerAttributes::SERVER_PORT]);
        $this->assertSame('write', $attributes['hypervel.db.connection.role']);

        $this->metricReader->collect();
        $duration = $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION);
        $this->assertInstanceOf(Histogram::class, $duration->data);
        $points = $duration->data->dataPoints;
        $this->assertIsArray($points);
        $this->assertCount(1, $points);
        $this->assertSame(0.1255, $points[0]->sum);
        $this->assertSame('postgresql', $points[0]->attributes->get(DbAttributes::DB_SYSTEM_NAME));
        $this->assertSame('application', $points[0]->attributes->get(DbAttributes::DB_NAMESPACE));
        $this->assertSame('SELECT', $points[0]->attributes->get(DbAttributes::DB_OPERATION_NAME));
        $this->assertSame('database.test', $points[0]->attributes->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(5432, $points[0]->attributes->get(ServerAttributes::SERVER_PORT));
        $this->assertSame('write', $points[0]->attributes->get('hypervel.db.connection.role'));
        $this->assertNull($points[0]->attributes->get(DbAttributes::DB_QUERY_TEXT));
    }

    public function testRecordsFailedQueriesWithTheUnderlyingErrorAndExactSpanHandoff(): void
    {
        $this->exceptionContexts->enable();
        $this->instrumentation()->register($this->options());
        $connection = $this->connection('mysql', 'billing', ['host' => 'mysql.test', 'port' => 3306]);
        $driverException = new PDOException('deadlock');
        $exception = new QueryException(
            'primary',
            'UPDATE invoices SET state = ? WHERE id = ?',
            ['paid', 42],
            $driverException,
        );
        $scope = $this->origins
            ->withOrigin(Context::getCurrent(), OperationOrigin::REQUEST)
            ->activate();

        try {
            $this->events->dispatch(new QueryFailed(
                'UPDATE invoices SET state = ? WHERE id = ?',
                ['paid', 42],
                50,
                $connection,
                $exception,
                'write',
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
        $this->assertSame(PDOException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertCount(1, $span->getEvents());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
        $this->assertSame(
            QueryException::class,
            $span->getEvents()[0]->getAttributes()->get(ExceptionAttributes::EXCEPTION_TYPE),
        );

        $this->metricReader->collect();
        $point = $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints[0];
        $this->assertSame(0.05, $point->sum);
        $this->assertSame(PDOException::class, $point->attributes->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testRecognizesOnlyOneConservativeLeadingSqlOperation(): void
    {
        $this->instrumentation()->register($this->options([
            'query_text' => false,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
        ]));
        $connection = $this->connection('sqlite', 'testing');
        $queries = [
            'select 1;' => 'SELECT',
            "\nINSERT INTO users DEFAULT VALUES\n" => 'INSERT',
            ' update users set active = 1 ; ' => 'UPDATE',
            'DELETE FROM users' => 'DELETE',
            'SELECT 1; DELETE FROM users' => null,
            'WITH users AS (SELECT 1) SELECT * FROM users' => null,
            '/* comment */ SELECT 1' => null,
            '' => null,
        ];

        foreach ($queries as $query => $operation) {
            $this->events->dispatch(new QueryExecuted($query, [], 1, $connection, 'write'));
        }

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(count($queries), $spans);

        foreach (array_values($queries) as $index => $operation) {
            $span = $spans[$index];

            $this->assertSame($operation, $span->getAttributes()->get(DbAttributes::DB_OPERATION_NAME));
            $this->assertSame(
                $operation === null ? 'testing' : "{$operation} testing",
                $span->getName(),
            );
        }
    }

    public function testQueryTextIsUnicodeBoundedAndNeverInterpolatesBindings(): void
    {
        $this->instrumentation()->register($this->options([
            'query_text_max_length' => 8,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
        ]));
        $connection = $this->connection('sqlite', 'testing');
        $event = new QueryExecuted(
            'SELECT 😀 FROM users WHERE id = ?',
            [new DatabaseBindingProbe],
            1,
            $connection,
            'write',
        );

        $this->events->dispatch($event);

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame(
            'SELECT 😀',
            $spans[0]->getAttributes()->get(DbAttributes::DB_QUERY_TEXT),
        );
    }

    public function testSkipsUnmeasuredManualQueriesWithoutReadingTheClock(): void
    {
        $this->instrumentation()->register($this->options());
        $connection = $this->connection('sqlite', 'testing');

        $this->events->dispatch(new QueryExecuted('SELECT 1', [], null, $connection, 'write'));

        $this->assertSame(0, $this->clock->calls);
        $this->assertCount(0, $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints);
    }

    public function testMetricsOnlyModeSkipsEveryTraceOnlyDetail(): void
    {
        $instrumentation = $this->probeInstrumentation();
        $instrumentation->register($this->options(['traces' => false]));
        $connection = $this->connection('sqlite', 'testing');

        $this->events->dispatch(new QueryExecuted('SELECT 😀 FROM users', [], 20, $connection, 'write'));

        $this->assertSame(0, $instrumentation->queryTextCalls);
        $this->assertSame(0, $this->clock->calls);
        $this->assertCount(0, $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(1, $this->metric(DbMetrics::DB_CLIENT_OPERATION_DURATION)->data->dataPoints);
    }

    public function testNonRecordingSpansSkipQueryTextWork(): void
    {
        $tracerProvider = TracerProvider::builder()
            ->setSampler(new AlwaysOffSampler)
            ->build();

        try {
            $instrumentation = $this->probeInstrumentation($tracerProvider);
            $instrumentation->register($this->options([
                'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
            ]));
            $connection = $this->connection('sqlite', 'testing');

            $this->events->dispatch(new QueryExecuted('SELECT 😀 FROM users', [], 20, $connection, 'write'));

            $this->assertSame(0, $instrumentation->queryTextCalls);
            $this->assertCount(0, $this->spanExporter->getSpans());
        } finally {
            $tracerProvider->shutdown();
        }
    }

    public function testOmitsAnUnknownReadEndpointInsteadOfReportingTheWriter(): void
    {
        $this->instrumentation()->register($this->options([
            'query_text' => false,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
        ]));
        $splitConnection = $this->connection(
            'pgsql',
            'application',
            ['host' => 'writer.test', 'port' => 5432],
        );
        $readConnection = $this->connection(
            'pgsql',
            'application',
            [
                'host' => 'reader.test',
                'port' => 5432,
                Connection::READ_WRITE_TYPE_CONFIG_KEY => 'read',
            ],
        );

        $this->events->dispatch(new QueryExecuted('SELECT 1', [], 1, $splitConnection, 'read'));
        $this->events->dispatch(new QueryExecuted('SELECT 1', [], 1, $readConnection, 'read'));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(2, $spans);
        $this->assertNull($spans[0]->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertNull($spans[0]->getAttributes()->get(ServerAttributes::SERVER_PORT));
        $this->assertSame('reader.test', $spans[1]->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(5432, $spans[1]->getAttributes()->get(ServerAttributes::SERVER_PORT));
    }

    public function testAllOutputsOffRegistersNoDatabaseListeners(): void
    {
        $this->instrumentation()->register($this->options([
            'traces' => false,
            'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
        ]));

        $this->assertFalse($this->events->hasListeners(QueryExecuted::class));
        $this->assertFalse($this->events->hasListeners(QueryFailed::class));
    }

    /**
     * Create the instrumentation under test.
     */
    private function instrumentation(): DatabaseInstrumentation
    {
        return new DatabaseInstrumentation(
            $this->events,
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $this->exceptionContexts,
            $this->origins,
            ProcessIdentity::eventWorker(0),
        );
    }

    /**
     * Create an instrumentation probe.
     */
    private function probeInstrumentation(
        ?TracerProviderInterface $tracerProvider = null,
    ): DatabaseInstrumentationProbe {
        return new DatabaseInstrumentationProbe(
            $this->events,
            $tracerProvider ?? $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $this->exceptionContexts,
            $this->origins,
            ProcessIdentity::eventWorker(0),
        );
    }

    /**
     * Create a database connection mock.
     *
     * @param array<string, mixed> $config
     */
    private function connection(
        string $driver,
        string $database,
        array $config = [],
        string $name = 'primary',
    ): Connection {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->zeroOrMoreTimes()->andReturn($name);
        $connection->shouldReceive('getDriverName')->zeroOrMoreTimes()->andReturn($driver);
        $connection->shouldReceive('getDatabaseName')->zeroOrMoreTimes()->andReturn($database);
        $connection->shouldReceive('getConfig')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static fn (?string $key = null): mixed => $key === null
                ? $config
                : ($config[$key] ?? null));

        return $connection;
    }

    /**
     * Return normalized database options.
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

class DatabaseInstrumentationProbe extends DatabaseInstrumentation
{
    public int $queryTextCalls = 0;

    /**
     * Return the bounded query template.
     */
    protected function queryText(string $query): string
    {
        ++$this->queryTextCalls;

        return parent::queryText($query);
    }
}

class DatabaseBindingProbe
{
    public function __toString(): string
    {
        throw new RuntimeException('Bindings must not be stringified.');
    }
}

class DatabaseTestClock implements ClockInterface
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
