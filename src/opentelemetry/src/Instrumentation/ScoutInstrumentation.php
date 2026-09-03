<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\ScoutTelemetryState;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ScoutInstrumentation extends AbstractInstrumentation implements EngineOperationObserver
{
    protected const string MODEL_ATTRIBUTE = 'hypervel.scout.model';

    protected const array DURATION_BOUNDARIES = [
        0.001,
        0.005,
        0.01,
        0.05,
        0.1,
        0.5,
        1,
        5,
        10,
    ];

    protected ?TracerInterface $tracer = null;

    protected ?HistogramInterface $duration = null;

    /**
     * Create Scout instrumentation.
     */
    public function __construct(
        protected EngineOperationRunner $operations,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected LogContextScopeFactory $logContextScopes,
    ) {
    }

    /**
     * Register the Scout operation observer and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.scout');
        }

        if ($this->metricEnabled(DbMetrics::DB_CLIENT_OPERATION_DURATION)) {
            $this->duration = $this->meterProvider
                ->getMeter('hypervel.scout')
                ->createHistogram(
                    DbMetrics::DB_CLIENT_OPERATION_DURATION,
                    's',
                    'Duration of Scout engine operations.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
        }

        if ($this->tracer !== null || $this->duration !== null) {
            $this->operations->observe($this);
        }
    }

    /**
     * Start observing a Scout engine operation.
     */
    public function starting(EngineOperation $operation): ScoutTelemetryState
    {
        $startedAt = $this->clock->now();
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;
        $scope = null;
        $logContextScope = null;
        $metricAttributes = $this->metricAttributes($operation);

        if ($this->tracer !== null) {
            $spanAttributes = $metricAttributes + [self::MODEL_ATTRIBUTE => $operation->modelClass];

            if ($operation->modelCount !== null && $operation->modelCount > 1) {
                $spanAttributes[DbAttributes::DB_OPERATION_BATCH_SIZE] = $operation->modelCount;
            }

            $span = $this->tracer
                ->spanBuilder("{$operation->operation} {$operation->index}")
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($spanAttributes)
                ->startSpan();
            $context = $span->storeInContext($parent);
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());
        }

        return new ScoutTelemetryState(
            $startedAt,
            $context,
            $span,
            $scope,
            $logContextScope,
            $metricAttributes,
        );
    }

    /**
     * Finish observing a Scout engine operation.
     */
    public function finished(
        EngineOperation $operation,
        mixed $token,
        ?Throwable $exception
    ): void {
        if ($exception instanceof CanceledException) {
            return;
        }

        /** @var ScoutTelemetryState $state */
        $state = $token;
        $finishedAt = $this->clock->now();
        $metricAttributes = $state->metricAttributes;

        if ($exception !== null) {
            $metricAttributes[ErrorAttributes::ERROR_TYPE] = $exception::class;
        }

        try {
            if ($state->span?->isRecording() && $exception !== null) {
                $state->span->recordException($exception);
                $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class);
                $state->span->setStatus(StatusCode::STATUS_ERROR);
            }

            $this->duration?->record(
                ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
                $metricAttributes,
                $state->context,
            );
        } finally {
            $state->logContextScope?->close();
            $state->scope?->detach();
            $state->span?->end($finishedAt);
        }
    }

    /**
     * Return metric-safe Scout engine-operation attributes.
     *
     * @return array<string, string>
     */
    protected function metricAttributes(EngineOperation $operation): array
    {
        return [
            DbAttributes::DB_SYSTEM_NAME => $operation->engineName,
            DbAttributes::DB_OPERATION_NAME => $operation->operation,
            DbAttributes::DB_NAMESPACE => $operation->index,
        ];
    }
}
