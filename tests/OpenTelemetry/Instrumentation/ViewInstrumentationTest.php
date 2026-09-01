<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use ArrayObject;
use Closure;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\OpenTelemetry\Context\CoroutineContextStorage;
use Hypervel\OpenTelemetry\Instrumentation\ViewInstrumentation;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\Tests\OpenTelemetry\Fixtures\CapturingAlwaysOffSampler;
use Hypervel\Tests\TestCase;
use Hypervel\View\Factory;
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

use function Hypervel\Coroutine\parallel;

class ViewInstrumentationTest extends TestCase
{
    private const string DURATION_METRIC = 'hypervel.view.render.duration';

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private Factory $views;

    private Closure $renderingObserver;

    private Closure $renderedObserver;

    private ViewInstrumentationTestClock $clock;

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
        $this->views = m::mock(Factory::class);
        $this->clock = new ViewInstrumentationTestClock;
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

    public function testRegistersNoObserversWhenEveryOutputIsDisabled(): void
    {
        $this->views->shouldNotReceive('observeRendering');
        $this->views->shouldNotReceive('observeRendered');

        $this->instrumentation()->register($this->options(traces: false, duration: false));

        $this->assertSame(0, $this->clock->calls);
    }

    public function testSuccessfulRenderUsesAmbientParentAndRecordsDuration(): void
    {
        $this->registerInstrumentation();
        $view = $this->view('reports.daily');
        $ambient = $this->tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $ambient->activate();

        try {
            $this->clock->timestamp = 1_000_000_000;
            ($this->renderingObserver)($view);
            $viewSpan = Span::getCurrent()->getContext();
            $this->assertNotSame($ambient->getContext()->getSpanId(), $viewSpan->getSpanId());

            $this->clock->timestamp = 4_000_000_000;
            ($this->renderedObserver)($view, null);
            $this->assertSame($ambient->getContext()->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        } finally {
            $ambientScope->detach();
            $ambient->end();
        }

        $span = $this->exportedSpan('view reports.daily');
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame($ambient->getContext()->getSpanId(), $span->getParentSpanId());
        $this->assertSame(1_000_000_000, $span->getStartEpochNanos());
        $this->assertSame(4_000_000_000, $span->getEndEpochNanos());
        $this->assertSame('reports.daily', $span->getAttributes()->get('hypervel.view.name'));
        $this->assertSame('success', $span->getAttributes()->get('result'));

        $this->metricReader->collect();
        $point = $this->histogramPoint();
        $this->assertSame(3, $point->sum);
        $this->assertSame('reports.daily', $point->attributes->get('hypervel.view.name'));
        $this->assertSame('success', $point->attributes->get('result'));
    }

    public function testRenderFailureRecordsTheExceptionAndMetricErrorType(): void
    {
        $this->registerInstrumentation();
        $view = $this->view('reports.daily');
        $exception = new RuntimeException('Render failed.');

        ($this->renderingObserver)($view);
        ($this->renderedObserver)($view, $exception);

        $span = $this->exportedSpan('view reports.daily');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('failure', $span->getAttributes()->get('result'));
        $this->assertSame(RuntimeException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame('Render failed.', $span->getEvents()[0]
            ->getAttributes()->get(ExceptionAttributes::EXCEPTION_MESSAGE));

        $this->metricReader->collect();
        $point = $this->histogramPoint();
        $this->assertSame('failure', $point->attributes->get('result'));
        $this->assertSame(RuntimeException::class, $point->attributes->get(ErrorAttributes::ERROR_TYPE));
    }

    public function testUnmatchedCompletionCannotPopAnOuterRender(): void
    {
        $this->registerInstrumentation(duration: false);
        $outer = $this->view('layouts.app');
        $inner = $this->view('partials.navigation', 0);

        ($this->renderingObserver)($outer);
        $outerSpan = Span::getCurrent()->getContext();
        ($this->renderedObserver)($inner, new RuntimeException('Earlier observer failed.'));

        $this->assertSame($outerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        ($this->renderedObserver)($outer, null);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(1, $this->spanExporter->getSpans());
    }

    public function testNestedRendersCompleteInStackOrder(): void
    {
        $this->registerInstrumentation(duration: false);
        $outer = $this->view('layouts.app');
        $inner = $this->view('partials.navigation');

        ($this->renderingObserver)($outer);
        $outerSpan = Span::getCurrent()->getContext();
        ($this->renderingObserver)($inner);
        $innerSpan = Span::getCurrent()->getContext();

        ($this->renderedObserver)($inner, null);
        $this->assertSame($outerSpan->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        ($this->renderedObserver)($outer, null);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertNotSame($outerSpan->getSpanId(), $innerSpan->getSpanId());
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testConcurrentRendersOfTheSameViewRemainIsolated(): void
    {
        $this->registerInstrumentation(duration: false);
        $view = $this->view('reports.daily', 2);

        $spanIds = parallel([
            function () use ($view): string {
                ($this->renderingObserver)($view);
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(10_000);
                ($this->renderedObserver)($view, null);
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
            function () use ($view): string {
                ($this->renderingObserver)($view);
                $spanId = Span::getCurrent()->getContext()->getSpanId();
                usleep(5_000);
                ($this->renderedObserver)($view, null);
                $this->assertFalse(Span::getCurrent()->getContext()->isValid());

                return $spanId;
            },
        ]);

        $this->assertNotSame($spanIds[0], $spanIds[1]);
        $this->assertCount(2, $this->spanExporter->getSpans());
    }

    public function testCancellationProducesNoFalseCompletionTelemetry(): void
    {
        $this->registerInstrumentation();
        $view = $this->view('reports.daily');

        ($this->renderingObserver)($view);
        $active = Span::getCurrent()->getContext();
        ($this->renderedObserver)($view, new CanceledException);

        $this->assertSame($active->getSpanId(), Span::getCurrent()->getContext()->getSpanId());
        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertCount(0, $this->metric()->data->dataPoints);
    }

    public function testMetricOnlyModeRecordsWithoutCreatingASpan(): void
    {
        $this->registerInstrumentation(traces: false);
        $view = $this->view('reports.daily');

        $this->clock->timestamp = 1_000_000_000;
        ($this->renderingObserver)($view);
        $this->clock->timestamp = 2_000_000_000;
        ($this->renderedObserver)($view, null);

        $this->assertSame([], $this->spanExporter->getSpans());
        $this->metricReader->collect();
        $this->assertSame(1, $this->histogramPoint()->sum);
    }

    public function testViewIdentityIsAvailableToTheSampler(): void
    {
        $sampler = new CapturingAlwaysOffSampler;
        $this->tracerProvider->shutdown();
        $this->tracerProvider = TracerProvider::builder()
            ->setSampler($sampler)
            ->build();
        $this->registerInstrumentation(duration: false);
        $view = $this->view('reports.daily');

        ($this->renderingObserver)($view);
        ($this->renderedObserver)($view, null);

        $this->assertCount(1, $sampler->samples);
        $this->assertSame(
            'reports.daily',
            $sampler->samples[0]['attributes']['hypervel.view.name'],
        );
        $this->assertSame([], $this->spanExporter->getSpans());
    }

    /**
     * Register view instrumentation and capture its observers.
     */
    private function registerInstrumentation(bool $traces = true, bool $duration = true): void
    {
        $this->views->shouldReceive('observeRendering')
            ->once()
            ->withArgs(function (Closure $observer): bool {
                $this->renderingObserver = $observer;

                return true;
            });
        $this->views->shouldReceive('observeRendered')
            ->once()
            ->withArgs(function (Closure $observer): bool {
                $this->renderedObserver = $observer;

                return true;
            });

        $this->instrumentation()->register($this->options($traces, $duration));
    }

    /**
     * Create view instrumentation.
     */
    private function instrumentation(): ViewInstrumentation
    {
        $logContextScopes = m::mock(LogContextScopeFactory::class);
        $logContextScopes->shouldReceive('activate')->zeroOrMoreTimes()->andReturnNull();

        return new ViewInstrumentation(
            $this->views,
            $this->tracerProvider,
            $this->meterProvider,
            $this->clock,
            $logContextScopes,
        );
    }

    /**
     * Return view instrumentation options.
     *
     * @return array<string, mixed>
     */
    private function options(bool $traces = true, bool $duration = true): array
    {
        return [
            'traces' => $traces,
            'metrics' => [self::DURATION_METRIC => $duration],
        ];
    }

    /**
     * Create a view test double with only its bounded name available.
     */
    private function view(string $name, int $calls = 1): ViewContract
    {
        $view = m::mock(ViewContract::class);
        $view->shouldReceive('name')->times($calls)->andReturn($name);

        return $view;
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
     * Return the exported duration metric.
     */
    private function metric(): Metric
    {
        foreach ($this->metricExporter->collect() as $metric) {
            if ($metric->name === self::DURATION_METRIC) {
                return $metric;
            }
        }

        $this->fail('The view duration metric was not exported.');
    }

    /**
     * Return the first point from the duration histogram.
     */
    private function histogramPoint(): HistogramDataPoint
    {
        $metric = $this->metric();
        $this->assertInstanceOf(Histogram::class, $metric->data);
        $this->assertCount(1, $metric->data->dataPoints);

        return $metric->data->dataPoints[0];
    }
}

class ViewInstrumentationTestClock implements ClockInterface
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
