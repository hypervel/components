<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\ExceptionInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\RequestTelemetryState;
use Hypervel\OpenTelemetry\Support\UserContextResolver;
use Hypervel\Support\Lottery;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderBuilder;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

class ExceptionInstrumentationTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Container $container;

    private ExceptionHandler $handler;

    private ArrayObject $records;

    private LoggerProvider $loggerProvider;

    private InMemoryMetricExporter $metricExporter;

    private ExportingReader $metricReader;

    private MeterProvider $meterProvider;

    private ExceptionContextRegistry $exceptionContexts;

    private OperationOrigin $origins;

    private UserContextResolver $userContexts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->container = new Container;
        $this->handler = new ExceptionHandler($this->container);
        $this->container->instance(ExceptionHandlerContract::class, $this->handler);
        $this->container->instance(LoggerInterface::class, new NullLogger);
        $this->records = new ArrayObject;
        $this->loggerProvider = (new LoggerProviderBuilder)
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new InMemoryLogExporter($this->records)))
            ->build();
        $this->metricExporter = new InMemoryMetricExporter;
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->metricReader)
            ->build();
        $this->exceptionContexts = new ExceptionContextRegistry;
        $this->origins = new OperationOrigin;
        $this->userContexts = m::mock(UserContextResolver::class);
    }

    protected function tearDownInCoroutine(): void
    {
        $this->loggerProvider->shutdown();
        $this->meterProvider->shutdown();
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testRecordsAReportedExceptionAsOneStandardLogAndMetric(): void
    {
        $this->instrumentation()->register($this->options());
        $this->handler->dontReportDuplicates();
        $exception = $this->caughtException();

        $this->handler->report($exception);
        $this->handler->report($exception);

        $this->assertTrue($this->exceptionContexts->hasRecorder());
        $this->assertCount(1, $this->records);
        $record = $this->records[0];
        $attributes = $record->getAttributes();
        $this->assertSame(1_700_000_000_000_000_000, $record->getTimestamp());
        $this->assertSame(Severity::ERROR->value, $record->getSeverityNumber());
        $this->assertSame('ERROR', $record->getSeverityText());
        $this->assertSame('exception', $record->getEventName());
        $this->assertSame('Application failed.', $record->getBody());
        $this->assertSame(RuntimeException::class, $attributes->get(ExceptionAttributes::EXCEPTION_TYPE));
        $this->assertSame('Application failed.', $attributes->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $this->assertIsString($attributes->get(ExceptionAttributes::EXCEPTION_STACKTRACE));
        $this->assertSame(__FILE__, $attributes->get(CodeAttributes::CODE_FILE_PATH));
        $this->assertIsInt($attributes->get(CodeAttributes::CODE_LINE_NUMBER));
        $this->assertStringEndsWith(
            'ExceptionInstrumentationTest::throwApplicationException',
            $attributes->get(CodeAttributes::CODE_FUNCTION_NAME),
        );

        $metric = $this->metric('hypervel.exceptions');
        $this->assertInstanceOf(Sum::class, $metric->data);
        $this->assertSame(1, $metric->data->dataPoints[0]->value);
        $this->assertSame(
            RuntimeException::class,
            $metric->data->dataPoints[0]->attributes->get(ExceptionAttributes::EXCEPTION_TYPE),
        );
        $this->assertNull($metric->data->dataPoints[0]->attributes->get('hypervel.exception.origin'));
    }

    public function testUsesAnExactEndedOperationContextAndOrigin(): void
    {
        $this->instrumentation()->register($this->options());
        $exception = new RuntimeException('Job failed.');
        $spanContext = SpanContext::create(
            '1234567890abcdef1234567890abcdef',
            '1234567890abcdef',
            TraceFlags::SAMPLED,
        );
        $context = $this->origins->withOrigin(
            Span::wrap($spanContext)->storeInContext(Context::getRoot()),
            OperationOrigin::JOB,
        );
        $this->exceptionContexts->associate($exception, $context, OperationOrigin::JOB);

        $this->handler->report($exception);

        $record = $this->records[0];
        $this->assertSame($spanContext->getTraceId(), $record->getSpanContext()?->getTraceId());
        $this->assertSame($spanContext->getSpanId(), $record->getSpanContext()?->getSpanId());
        $this->assertSame(OperationOrigin::JOB, $record->getAttributes()->get('hypervel.exception.origin'));
        $this->assertNull($this->exceptionContexts->take($exception));
        $this->assertSame(
            OperationOrigin::JOB,
            $this->metric('hypervel.exceptions')->data->dataPoints[0]->attributes->get('hypervel.exception.origin'),
        );
    }

    public function testRequestOnlyStateSuppliesLazyUserContextToExceptionLogs(): void
    {
        $this->instrumentation(userContext: true)->register($this->options());
        $request = Request::create('/users', 'GET');
        RequestContext::set($request);
        $state = new RequestTelemetryState(
            $request,
            0,
            null,
            null,
            null,
            null,
            [],
            false,
        );
        RequestTelemetryState::set($state);
        $this->userContexts->shouldReceive('resolve')
            ->once()
            ->with($state)
            ->andReturn(['user.id' => '42']);

        $this->handler->report(new RuntimeException('Request failed.'));

        $record = $this->records[0];
        $this->assertSame(OperationOrigin::REQUEST, $record->getAttributes()->get('hypervel.exception.origin'));
        $this->assertSame('42', $record->getAttributes()->get('user.id'));
    }

    /**
     * @param array<string, bool> $options
     */
    #[DataProvider('privacyProvider')]
    public function testMessageAndStackTracePrivacySwitchesAreIndependent(
        array $options,
        string $body,
        bool $hasMessage,
        bool $hasStackTrace,
    ): void {
        $this->instrumentation()->register($this->options($options));

        $this->handler->report(new RuntimeException('Private message.'));

        $record = $this->records[0];
        $attributes = $record->getAttributes();
        $this->assertSame($body, $record->getBody());
        $this->assertSame(RuntimeException::class, $attributes->get(ExceptionAttributes::EXCEPTION_TYPE));
        $hasMessage
            ? $this->assertSame('Private message.', $attributes->get(ExceptionAttributes::EXCEPTION_MESSAGE))
            : $this->assertNull($attributes->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $hasStackTrace
            ? $this->assertIsString($attributes->get(ExceptionAttributes::EXCEPTION_STACKTRACE))
            : $this->assertNull($attributes->get(ExceptionAttributes::EXCEPTION_STACKTRACE));
    }

    /**
     * @return iterable<string, array{array<string, bool>, string, bool, bool}>
     */
    public static function privacyProvider(): iterable
    {
        yield 'message only' => [
            ['message' => true, 'stack_trace' => false],
            'Private message.',
            true,
            false,
        ];
        yield 'stack trace only' => [
            ['message' => false, 'stack_trace' => true],
            RuntimeException::class,
            false,
            true,
        ];
        yield 'type only' => [
            ['message' => false, 'stack_trace' => false],
            RuntimeException::class,
            false,
            false,
        ];
    }

    public function testHandlerFilteringAndEarlierReportersRemainAuthoritative(): void
    {
        $this->handler->ignore(IgnoredInstrumentationException::class);
        $this->handler->throttleUsing(
            fn (ThrottledInstrumentationException $exception): Lottery => Lottery::odds(0, 1),
        );
        $this->handler->reportable(
            fn (HandledInstrumentationException $exception): bool => false,
        );
        $this->instrumentation()->register($this->options());

        $this->handler->report(new IgnoredInstrumentationException);
        $this->handler->report(new ThrottledInstrumentationException);
        $this->handler->report(new HandledInstrumentationException);
        $this->handler->report(new SelfReportingInstrumentationException);

        $this->assertCount(0, $this->records);
        $this->assertSame([], $this->metric('hypervel.exceptions')->data->dataPoints);
    }

    public function testEnrichersRunInOrderAndOrdinaryFailuresDoNotPreventEmission(): void
    {
        $this->instrumentation(enrichers: [
            function (LogRecordBuilderInterface $record): void {
                $record->setAttribute('enricher.first', true);
            },
            function (): void {
                throw new RuntimeException('Enricher failed.');
            },
            function (LogRecordBuilderInterface $record): void {
                $record->setAttribute('enricher.last', true);
            },
        ])->register($this->options());

        $this->handler->report(new RuntimeException('Application failed.'));

        $attributes = $this->records[0]->getAttributes();
        $this->assertTrue($attributes->get('enricher.first'));
        $this->assertTrue($attributes->get('enricher.last'));
    }

    public function testMetricsOnlyModeDoesNotEnableDirectLogDeduplication(): void
    {
        $this->instrumentation()->register($this->options([
            'logs' => false,
            'metrics' => ['hypervel.exceptions' => true],
        ]));

        $this->handler->report(new RuntimeException('Application failed.'));

        $this->assertFalse($this->exceptionContexts->hasRecorder());
        $this->assertCount(0, $this->records);
        $this->assertSame(1, $this->metric('hypervel.exceptions')->data->dataPoints[0]->value);
    }

    public function testCustomHandlerWithoutReportableSupportIsLeftUntouched(): void
    {
        $container = new Container;
        $handler = m::mock(ExceptionHandlerContract::class);
        $container->instance(ExceptionHandlerContract::class, $handler);
        $instrumentation = $this->instrumentation(container: $container);

        $instrumentation->register($this->options());

        $this->assertFalse($this->exceptionContexts->hasRecorder());
        $this->assertCount(0, $this->records);
    }

    /**
     * Create exception instrumentation.
     *
     * @param list<callable> $enrichers
     */
    private function instrumentation(
        bool $userContext = false,
        array $enrichers = [],
        ?Container $container = null,
    ): ExceptionInstrumentation {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('configuration')->zeroOrMoreTimes()->andReturn([
            'instrumentation' => [
                HttpServerInstrumentation::class => $userContext
                    ? ['user_context' => true]
                    : false,
            ],
        ]);
        $manager->shouldReceive('exceptionEnrichers')->zeroOrMoreTimes()->andReturn($enrichers);

        return new ExceptionInstrumentation(
            $container ?? $this->container,
            $this->loggerProvider,
            $this->meterProvider,
            new ExceptionInstrumentationTestClock,
            $manager,
            $this->exceptionContexts,
            $this->origins,
            ProcessIdentity::eventWorker(0),
            $this->userContexts,
        );
    }

    /**
     * Return exception instrumentation options.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function options(array $overrides = []): array
    {
        return array_replace([
            'logs' => true,
            'message' => true,
            'stack_trace' => true,
            'metrics' => ['hypervel.exceptions' => true],
        ], $overrides);
    }

    /**
     * Return one exported metric by name.
     */
    private function metric(string $name): Metric
    {
        $this->metricReader->collect();

        foreach ($this->metricExporter->collect() as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        $this->fail("Metric [{$name}] was not exported.");
    }

    /**
     * Create an exception with a useful first stack frame.
     */
    private function caughtException(): RuntimeException
    {
        try {
            $this->throwApplicationException();
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    /**
     * Throw a test exception.
     */
    private function throwApplicationException(): never
    {
        throw new RuntimeException('Application failed.');
    }
}

class ExceptionInstrumentationTestClock implements ClockInterface
{
    /**
     * Return a deterministic timestamp.
     */
    public function now(): int
    {
        return 1_700_000_000_000_000_000;
    }
}

class IgnoredInstrumentationException extends RuntimeException
{
}

class ThrottledInstrumentationException extends RuntimeException
{
}

class HandledInstrumentationException extends RuntimeException
{
}

class SelfReportingInstrumentationException extends RuntimeException
{
    /**
     * Consume framework reporting before reportable callbacks.
     */
    public function report(): void
    {
    }
}
