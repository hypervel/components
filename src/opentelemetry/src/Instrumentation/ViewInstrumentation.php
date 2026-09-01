<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\ViewTelemetryState;
use Hypervel\View\Factory;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ViewInstrumentation extends AbstractInstrumentation
{
    protected const string STATE_CONTEXT_KEY = '__opentelemetry.view_states';

    protected const string DURATION_METRIC = 'hypervel.view.render.duration';

    protected const string VIEW_ATTRIBUTE = 'hypervel.view.name';

    protected const array DURATION_BOUNDARIES = [
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1,
        2.5,
        5,
        7.5,
        10,
    ];

    protected ?TracerInterface $tracer = null;

    protected ?HistogramInterface $duration = null;

    /**
     * Create view instrumentation.
     */
    public function __construct(
        protected Factory $views,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected LogContextScopeFactory $logContextScopes,
    ) {
    }

    /**
     * Register view observers and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.view');
        }

        if ($this->metricEnabled(self::DURATION_METRIC)) {
            $this->duration = $this->meterProvider
                ->getMeter('hypervel.view')
                ->createHistogram(
                    self::DURATION_METRIC,
                    's',
                    'Duration of view renders.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
        }

        if ($this->tracer === null && $this->duration === null) {
            return;
        }

        $this->views->observeRendering(function (ViewContract $view): void {
            $this->start($view);
        });
        $this->views->observeRendered(function (ViewContract $view, ?Throwable $exception): void {
            $this->finish($view, $exception);
        });
    }

    /**
     * Start view-render telemetry.
     */
    protected function start(ViewContract $view): void
    {
        $viewName = $view->name();
        $startedAt = $this->clock->now();
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;
        $scope = null;
        $logContextScope = null;
        $attributes = [self::VIEW_ATTRIBUTE => $viewName];

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder("view {$viewName}")
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $span->storeInContext($parent);
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());
        }

        /** @var list<ViewTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $states[] = new ViewTelemetryState(
            $view,
            $startedAt,
            $context,
            $span,
            $scope,
            $logContextScope,
            $attributes,
        );
        CoroutineContext::set(self::STATE_CONTEXT_KEY, $states);
    }

    /**
     * Complete view-render telemetry.
     */
    protected function finish(ViewContract $view, ?Throwable $exception): void
    {
        // Direct notifyRendered() callers can supply cancellation; the framework render path skips this callback.
        if ($exception instanceof CanceledException) {
            return;
        }

        $state = $this->takeState($view);

        if ($state === null) {
            return;
        }

        $finishedAt = $this->clock->now();
        $result = $exception === null ? 'success' : 'failure';
        $attributes = $state->attributes + ['result' => $result];

        if ($exception !== null) {
            $attributes[ErrorAttributes::ERROR_TYPE] = $exception::class;
        }

        try {
            if ($state->span?->isRecording()) {
                $state->span->setAttribute('result', $result);

                if ($exception !== null) {
                    $state->span->recordException($exception);
                    $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
                    $state->span->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            $this->duration?->record(
                ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
                $attributes,
                $state->context,
            );
        } finally {
            $state->logContextScope?->close();
            $state->scope?->detach();
            $state->span?->end($finishedAt);
        }
    }

    /**
     * Remove and return the matching top view-render state.
     */
    protected function takeState(ViewContract $view): ?ViewTelemetryState
    {
        /** @var list<ViewTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $last = array_key_last($states);

        if ($last === null || $states[$last]->view !== $view) {
            return null;
        }

        $state = array_pop($states);

        if ($states === []) {
            CoroutineContext::forget(self::STATE_CONTEXT_KEY);
        } else {
            CoroutineContext::set(self::STATE_CONTEXT_KEY, $states);
        }

        return $state;
    }
}
