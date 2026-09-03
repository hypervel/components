<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Logging;

use ArrayObject;
use DateTimeImmutable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Logging\OpenTelemetryHandler;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributeValidator;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderBuilder;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use RuntimeException;
use stdClass;

class OpenTelemetryHandlerTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private ArrayObject $records;

    private LoggerProvider $loggerProvider;

    private OpenTelemetryManager $manager;

    private ExceptionContextRegistry $exceptionContexts;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousStorage = Context::storage();
    }

    protected function setUpInCoroutine(): void
    {
        Context::setStorage(new CoroutineContextStorage(Context::getRoot()));
        $this->records = new ArrayObject;
        $this->loggerProvider = (new LoggerProviderBuilder)
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new InMemoryExporter($this->records)))
            ->build();
        $this->manager = m::mock(OpenTelemetryManager::class);
        $this->exceptionContexts = new ExceptionContextRegistry;
        $this->container = m::mock(Container::class);
    }

    protected function tearDownInCoroutine(): void
    {
        $this->loggerProvider->shutdown();
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->previousStorage);

        parent::tearDown();
    }

    public function testDisabledSignalReturnsImmediatelyAndContinuesBubbling(): void
    {
        $this->manager->shouldReceive('logsEnabled')->twice()->andReturnFalse();
        $handler = $this->handler();
        $record = $this->record();

        $this->assertFalse($handler->isHandling($record));
        $this->assertFalse($handler->handle($record));
        $this->assertCount(0, $this->records);
        $this->assertNull($record->formatted);
    }

    public function testItPreservesStructuredRecordFieldsAndCurrentTraceContext(): void
    {
        $this->manager->shouldReceive('logsEnabled')->once()->andReturnTrue();
        $this->container->shouldNotReceive('make');
        $handler = $this->handler();
        $spanContext = SpanContext::create(
            '1234567890abcdef1234567890abcdef',
            '1234567890abcdef',
            TraceFlags::SAMPLED,
        );
        $scope = Span::wrap($spanContext)->storeInContext(Context::getCurrent())->activate();
        $record = $this->record(
            level: Level::Warning,
            context: [
                'tenant.id' => 42,
                'feature.enabled' => true,
                'ratios' => [1, 2.5],
                'empty' => [],
                'mapping' => ['name' => 'Taylor'],
                'nullable' => null,
                'object' => new stdClass,
            ],
        );

        try {
            $this->assertFalse($handler->handle($record));
        } finally {
            $scope->detach();
        }

        $this->assertCount(1, $this->records);
        $exported = $this->records[0];
        $this->assertSame(1_700_000_000_123_456_000, $exported->getTimestamp());
        $this->assertSame(Severity::WARN->value, $exported->getSeverityNumber());
        $this->assertSame('WARNING', $exported->getSeverityText());
        $this->assertSame('Application log.', $exported->getBody());
        $this->assertSame(42, $exported->getAttributes()->get('tenant.id'));
        $this->assertTrue($exported->getAttributes()->get('feature.enabled'));
        $this->assertSame([1, 2.5], $exported->getAttributes()->get('ratios'));
        $this->assertSame([], $exported->getAttributes()->get('empty'));
        $this->assertNull($exported->getAttributes()->get('mapping'));
        $this->assertNull($exported->getAttributes()->get('nullable'));
        $this->assertNull($exported->getAttributes()->get('object'));
        $this->assertSame($spanContext->getTraceId(), $exported->getSpanContext()?->getTraceId());
        $this->assertSame($spanContext->getSpanId(), $exported->getSpanContext()?->getSpanId());
        $this->assertNull($record->formatted);
    }

    public function testOrdinaryExceptionContextBecomesStandardExceptionAttributes(): void
    {
        $this->manager->shouldReceive('logsEnabled')->once()->andReturnTrue();
        $this->container->shouldNotReceive('make');
        $exception = new RuntimeException('Application failed.');

        $this->handler()->handle($this->record(context: [
            'exception' => $exception,
            'attempt' => 3,
        ]));

        $exported = $this->records[0];
        $this->assertSame(RuntimeException::class, $exported->getAttributes()->get(ExceptionAttributes::EXCEPTION_TYPE));
        $this->assertSame('Application failed.', $exported->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
        $this->assertIsString($exported->getAttributes()->get(ExceptionAttributes::EXCEPTION_STACKTRACE));
        $this->assertSame(3, $exported->getAttributes()->get('attempt'));
        $this->assertNull($exported->getAttributes()->get('exception'));
    }

    public function testItSuppressesOnlyTheExactExceptionBeingDirectlyReported(): void
    {
        $this->manager->shouldReceive('logsEnabled')->twice()->andReturnTrue();
        $this->exceptionContexts->markRecorderRegistered();
        $reported = new RuntimeException('Reported.');
        $other = new RuntimeException('Other.');
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldReceive('isReporting')->once()->with($reported)->andReturnTrue();
        $exceptionHandler->shouldReceive('isReporting')->once()->with($other)->andReturnFalse();
        $this->container->shouldReceive('make')
            ->once()
            ->with(ExceptionHandlerContract::class)
            ->andReturn($exceptionHandler);
        $handler = $this->handler();

        $this->assertFalse($handler->handle($this->record(context: ['exception' => $reported])));
        $this->assertFalse($handler->handle($this->record(context: ['exception' => $other])));

        $this->assertCount(1, $this->records);
        $this->assertSame('Other.', $this->records[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));
    }

    public function testConfiguredLevelStillFiltersRecords(): void
    {
        $this->manager->shouldReceive('logsEnabled')->twice()->andReturnTrue();
        $handler = $this->handler(Level::Error);

        $this->assertFalse($handler->isHandling($this->record(level: Level::Info)));
        $this->assertTrue($handler->isHandling($this->record(level: Level::Error)));
    }

    /**
     * Create an OpenTelemetry handler.
     */
    private function handler(int|string|Level $level = Level::Debug): OpenTelemetryHandler
    {
        return new OpenTelemetryHandler(
            $this->loggerProvider->getLogger('application'),
            $this->manager,
            $this->exceptionContexts,
            $this->container,
            new AttributeValidator,
            $level,
        );
    }

    /**
     * Create a Monolog record.
     *
     * @param array<mixed> $context
     */
    private function record(Level $level = Level::Info, array $context = []): LogRecord
    {
        $datetime = DateTimeImmutable::createFromFormat('U.u', '1700000000.123456');
        $this->assertInstanceOf(DateTimeImmutable::class, $datetime);

        return new LogRecord(
            $datetime,
            'application',
            $level,
            'Application log.',
            $context,
        );
    }
}
