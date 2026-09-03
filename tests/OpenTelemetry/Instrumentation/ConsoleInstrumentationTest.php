<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Events\Dispatcher;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\ConsoleInstrumentation;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
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
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Input\ArrayInput;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ConsoleInstrumentationTest extends TestCase
{
    private const string DURATION_METRIC = 'hypervel.console.command.duration';

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Dispatcher $events;

    private ConsoleInstrumentationTestClock $clock;

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
        $this->clock = new ConsoleInstrumentationTestClock;
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
        $this->instrumentation()->register($this->options(traces: false, duration: false));

        $this->assertFalse($this->events->hasListeners(BeforeHandle::class));
        $this->assertFalse($this->events->hasListeners(AfterExecute::class));
        $this->assertSame(0, $this->clock->calls);
    }

    public function testEmptyAllowlistRegistersNothing(): void
    {
        $this->instrumentation()->register($this->options(commands: []));

        $this->assertFalse($this->events->hasListeners(BeforeHandle::class));
        $this->assertFalse($this->events->hasListeners(AfterExecute::class));
        $this->assertSame(0, $this->clock->calls);
    }

    public function testSuccessfulCommandUsesAmbientParentAndRecordsDuration(): void
    {
        $this->instrumentation()->register($this->options());
        $command = new ConsoleInstrumentationCommand('reports:daily');
        $ambient = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $ambient->activate();

        try {
            $this->clock->timestamp = 1_000_000_000;
            $input = $this->startCommand($command);
            $commandSpan = Span::getCurrent()->getContext();
            $this->assertNotSame($ambient->getContext()->getSpanId(), $commandSpan->getSpanId());

            $this->clock->timestamp = 4_000_000_000;
            $this->finishCommand($command, $input);
            $this->assertSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        } finally {
            $ambientScope->detach();
            $ambient->end();
        }

        $span = $this->exportedSpan('reports:daily');
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame($ambient->getContext()->getSpanId(), $span->getParentSpanId());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(4_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('reports:daily', $span->getAttributes()->get('hypervel.console.command'));
        $this->assertSame('success', $span->getAttributes()->get('result'));
        $this->assertSame(0, $span->getAttributes()->get('hypervel.console.exit_code'));

        $this->metricReader->collect();
        $point = $this->histogramPoint();
        $this->assertSame(3, $point->sum);
        $this->assertSame('reports:daily', $point->attributes->get('hypervel.console.command'));
        $this->assertSame('success', $point->attributes->get('result'));
        $this->assertSame(0, $point->attributes->get('hypervel.console.exit_code'));
    }

    public function testAllowAndExcludePatternsFilterBeforeTimingOrContextWork(): void
    {
        $this->instrumentation()->register($this->options(
            commands: ['reports:*', 'users:sync'],
            except: ['reports:secret*'],
        ));

        foreach (['reports:secret', 'cache:clear', ''] as $name) {
            $command = new ConsoleInstrumentationCommand($name === '' ? null : $name);
            $input = $this->startCommand($command);
            $this->finishCommand($command, $input);
        }

        $this->assertSame(0, $this->clock->calls);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());

        $allowed = new ConsoleInstrumentationCommand('reports:daily');
        $input = $this->startCommand($allowed);
        $this->finishCommand($allowed, $input);

        $this->assertSame(2, $this->clock->calls);
        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function testNonZeroExitMarksTheSpanAndMetricAsFailed(): void
    {
        $this->instrumentation()->register($this->options());
        $command = new ConsoleInstrumentationCommand('reports:daily');

        $input = $this->startCommand($command);
        $this->finishCommand($command, $input, exitCode: 7);

        $span = $this->exportedSpan('reports:daily');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('failure', $span->getAttributes()->get('result'));
        $this->assertSame(7, $span->getAttributes()->get('hypervel.console.exit_code'));
        $this->assertNull($span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));

        $this->metricReader->collect();
        $point = $this->histogramPoint();
        $this->assertSame('failure', $point->attributes->get('result'));
        $this->assertSame(7, $point->attributes->get('hypervel.console.exit_code'));
    }

    public function testThrowableIsRecordedAndHandedToExceptionTelemetry(): void
    {
        $this->exceptionContexts->enable();
        $this->instrumentation()->register($this->options());
        $command = new ConsoleInstrumentationCommand('reports:daily');
        $exception = new RuntimeException('Command failed.');

        $input = $this->startCommand($command);
        $this->finishCommand($command, $input, $exception, 1);

        $span = $this->exportedSpan('reports:daily');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Command failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));

        $handoff = $this->exceptionContexts->take($exception);
        $this->assertNotNull($handoff);
        $this->assertSame(OperationOrigin::CONSOLE, $handoff->origin);
        $this->assertSame($span->getSpanId(), Span::fromContext($handoff->context)->getContext()->getSpanId());

        $this->metricReader->collect();
        $this->assertSame(
            RuntimeException::class,
            $this->histogramPoint()->attributes->get(ErrorAttributes::ERROR_TYPE),
        );
    }

    public function testCancellationProducesNoFalseCompletionTelemetry(): void
    {
        $this->instrumentation()->register($this->options());
        $command = new ConsoleInstrumentationCommand('reports:daily');

        $input = $this->startCommand($command);
        $active = Span::getCurrent()->getContext();
        $this->finishCommand($command, $input, new CanceledException, 1);

        $this->assertTrue($active->isValid());
        $this->assertSame($active->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric(self::DURATION_METRIC)->data->dataPoints);
    }

    public function testNestedCommandsOnlyCompleteTheExactTopState(): void
    {
        $this->instrumentation()->register($this->options(duration: false));
        $outer = new ConsoleInstrumentationCommand('outer');
        $inner = new ConsoleInstrumentationCommand('inner');

        $outerInput = $this->startCommand($outer);
        $outerSpan = Span::getCurrent()->getContext();
        $innerInput = $this->startCommand($inner);
        $innerSpan = Span::getCurrent()->getContext();

        $this->finishCommand($outer, $outerInput);
        $this->assertSame($innerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());

        $this->finishCommand($inner, $innerInput);
        $this->assertSame($outerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->finishCommand($outer, $outerInput);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testConcurrentRunsOfTheSameCommandRemainIsolated(): void
    {
        $this->instrumentation()->register($this->options(duration: false));
        $command = new ConsoleInstrumentationCommand('reports:daily');

        $spanIds = parallel([
            function () use ($command): string {
                $input = $this->startCommand($command);
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(10_000);
                $this->finishCommand($command, $input);
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
            function () use ($command): string {
                $input = $this->startCommand($command);
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(5_000);
                $this->finishCommand($command, $input);
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
        ]);

        $this->assertNotSame($spanIds[0], $spanIds[1]);
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testMetricOnlyModeRecordsWithoutCreatingASpan(): void
    {
        $this->instrumentation()->register($this->options(traces: false));
        $command = new ConsoleInstrumentationCommand('reports:daily');

        $this->clock->timestamp = 1_000_000_000;
        $input = $this->startCommand($command);
        $this->clock->timestamp = 2_000_000_000;
        $this->finishCommand($command, $input);

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame(1, $this->histogramPoint()->sum);
    }

    public function testTraceOnlyModeCreatesNoMetric(): void
    {
        $this->instrumentation()->register($this->options(duration: false));
        $command = new ConsoleInstrumentationCommand('reports:daily');

        $input = $this->startCommand($command);
        $this->finishCommand($command, $input);

        $this->assertCount(1, $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame([], $this->metricExporter->collect());
    }

    public function testCommandIdentityIsAvailableToTheSampler(): void
    {
        $sampler = new CapturingAlwaysOffSampler;
        $this->tracerProvider->shutdown();
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler($sampler)
            ->build();
        $this->instrumentation()->register($this->options(duration: false));
        $command = new ConsoleInstrumentationCommand('reports:daily');

        $input = $this->startCommand($command);
        $this->finishCommand($command, $input);

        $this->assertCount(1, $sampler->samples);
        $this->assertSame(
            'reports:daily',
            $sampler->samples[0]['attributes']['hypervel.console.command'],
        );
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    /**
     * Dispatch a command-start event with its input.
     */
    private function startCommand(Command $command): ArrayInput
    {
        $input = new ArrayInput([]);
        $this->events->dispatch(new BeforeHandle($command, $input));

        return $input;
    }

    /**
     * Dispatch a command-completion event with its input.
     */
    private function finishCommand(
        Command $command,
        ArrayInput $input,
        ?Throwable $throwable = null,
        int $exitCode = 0,
    ): void {
        $this->events->dispatch(new AfterExecute($command, $throwable, $input, $exitCode));
    }

    /**
     * Create console instrumentation.
     */
    private function instrumentation(): ConsoleInstrumentation
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new ConsoleInstrumentation(
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
     * Return console instrumentation options.
     *
     * @param list<string> $commands
     * @param list<string> $except
     * @return array<string, mixed>
     */
    private function options(
        bool $traces = true,
        bool $duration = true,
        array $commands = ['*'],
        array $except = [],
    ): array {
        return [
            'traces' => $traces,
            'commands' => $commands,
            'except' => $except,
            'metrics' => [self::DURATION_METRIC => $duration],
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
     * Return the first point from the duration histogram.
     */
    private function histogramPoint(): HistogramDataPoint
    {
        $metric = $this->metric(self::DURATION_METRIC);
        $this->assertInstanceOf(Histogram::class, $metric->data);
        $this->assertCount(1, $metric->data->dataPoints);

        return $metric->data->dataPoints[0];
    }
}

class ConsoleInstrumentationTestClock implements ClockInterface
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

class ConsoleInstrumentationCommand extends Command
{
}
