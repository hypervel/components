<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\SchedulerTelemetryState;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Swoole\Coroutine\CanceledException;

class SchedulerInstrumentation extends AbstractInstrumentation
{
    protected const string STATE_CONTEXT_KEY = '__opentelemetry.scheduler_states';

    protected const string DURATION_METRIC = 'hypervel.scheduler.task.duration';

    protected const string EXECUTIONS_METRIC = 'hypervel.scheduler.task.executions';

    protected const string TASK_ATTRIBUTE = 'hypervel.scheduler.task';

    protected const string EXIT_CODE_ATTRIBUTE = 'hypervel.scheduler.exit_code';

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

    protected ?CounterInterface $executions = null;

    /**
     * Create scheduler instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
        protected LogContextScopeFactory $logContextScopes,
    ) {
    }

    /**
     * Register scheduler listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.scheduler');
        }

        $meter = null;

        if ($this->metricEnabled(self::DURATION_METRIC)) {
            $meter = $this->meterProvider->getMeter('hypervel.scheduler');
            $this->duration = $meter->createHistogram(
                self::DURATION_METRIC,
                's',
                'Duration of scheduled task executions.',
                ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
            );
        }

        if ($this->metricEnabled(self::EXECUTIONS_METRIC)) {
            $meter ??= $this->meterProvider->getMeter('hypervel.scheduler');
            $this->executions = $meter->createCounter(
                self::EXECUTIONS_METRIC,
                '{execution}',
                'The number of scheduled task executions.',
            );
        }

        if ($this->tracer === null && $this->duration === null && $this->executions === null) {
            return;
        }

        $this->events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->start($event);
        });
        $this->events->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            $this->finish($event);
        });
        $this->events->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $this->fail($event);
        });
    }

    /**
     * Start scheduled-task telemetry.
     */
    protected function start(ScheduledTaskStarting $event): void
    {
        $startedAt = $this->tracer === null && $this->duration === null
            ? 0
            : $this->clock->now();
        $task = $this->taskName($event->task);
        $context = Context::getRoot();
        $span = null;
        $scope = null;
        $logContextScope = null;
        $attributes = [self::TASK_ATTRIBUTE => $task];

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder("schedule {$task}")
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent(Context::getRoot())
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $this->origins->withOrigin(
                $span->storeInContext(Context::getRoot()),
                OperationOrigin::SCHEDULE,
            );
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());
        }

        /** @var list<SchedulerTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $states[] = new SchedulerTelemetryState(
            $event->task,
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
     * Complete a successful or non-zero scheduled task.
     */
    protected function finish(ScheduledTaskFinished $event): void
    {
        $state = $this->takeState($event->task);

        if ($state === null) {
            return;
        }

        $finishedAt = $state->span === null && $this->duration === null
            ? 0
            : $this->clock->now();
        $exitCode = $event->task->exitCode();
        $result = $exitCode === null || $exitCode === 0 ? 'success' : 'failure';
        $attributes = $state->attributes + ['result' => $result];

        try {
            if ($state->span?->isRecording()) {
                $state->span->setAttribute('result', $result);

                if ($exitCode !== null) {
                    $state->span->setAttribute(self::EXIT_CODE_ATTRIBUTE, $exitCode);
                }

                if ($exitCode !== null && $exitCode !== 0) {
                    $state->span->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            $this->recordMetrics($state, $finishedAt, $attributes);
        } finally {
            $this->close($state, $finishedAt);
        }
    }

    /**
     * Complete a failed scheduled task.
     */
    protected function fail(ScheduledTaskFailed $event): void
    {
        if ($event->exception instanceof CanceledException) {
            return;
        }

        $state = $this->takeState($event->task);

        if ($state === null) {
            return;
        }

        $finishedAt = $state->span === null && $this->duration === null
            ? 0
            : $this->clock->now();
        $attributes = $state->attributes + ['result' => 'failure'];

        try {
            if ($state->span?->isRecording()) {
                $state->span->recordException($event->exception);
                $state->span->setAttribute('result', 'failure');
                $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $event->exception::class);
                $state->span->setStatus(StatusCode::STATUS_ERROR);
            }

            if ($state->span !== null) {
                $this->exceptionContexts->associate(
                    $event->exception,
                    $state->context,
                    $this->origins->resolve($state->context, $this->identity),
                );
            }

            $this->recordMetrics($state, $finishedAt, $attributes);
        } finally {
            $this->close($state, $finishedAt);
        }
    }

    /**
     * Record enabled scheduled-task metrics.
     *
     * @param array<string, string> $attributes
     */
    protected function recordMetrics(
        SchedulerTelemetryState $state,
        int $finishedAt,
        array $attributes,
    ): void {
        $this->duration?->record(
            ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
            $attributes,
            $state->context,
        );
        $this->executions?->add(1, $attributes, $state->context);
    }

    /**
     * Close a scheduled-task telemetry scope.
     */
    protected function close(SchedulerTelemetryState $state, int $finishedAt): void
    {
        $state->logContextScope?->close();
        $state->scope?->detach();
        $state->span?->end($finishedAt);
    }

    /**
     * Remove and return the matching top scheduled-task state.
     */
    protected function takeState(Event $task): ?SchedulerTelemetryState
    {
        /** @var list<SchedulerTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $last = array_key_last($states);

        if ($last === null || $states[$last]->task !== $task) {
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

    /**
     * Return a stable scheduled-task identity.
     */
    protected function taskName(Event $task): string
    {
        if ($task->description !== null && $task->description !== '') {
            return $task->description;
        }

        if ($task instanceof CallbackEvent) {
            return 'callback';
        }

        if ($task->isSystem) {
            return 'process';
        }

        $command = preg_split('/\s+/', trim($task->command ?? ''), 2)[0] ?? '';

        return $command === '' ? 'command' : $command;
    }
}
