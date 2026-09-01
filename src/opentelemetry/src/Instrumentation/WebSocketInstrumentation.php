<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\WebSocketTelemetryState;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageHandled;
use Hypervel\WebSocketServer\Events\MessageReceived;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Swoole\Coroutine\CanceledException;
use Swoole\WebSocket\Frame;

class WebSocketInstrumentation extends AbstractInstrumentation
{
    protected const string STATE_CONTEXT_KEY = '__opentelemetry.websocket_states';

    protected const string DURATION_METRIC = 'hypervel.websocket.message.duration';

    protected const string MESSAGES_METRIC = 'hypervel.websocket.messages';

    protected const string ACTIVE_CONNECTIONS_METRIC = 'hypervel.websocket.active_connections';

    protected const string OPCODE_ATTRIBUTE = 'hypervel.websocket.opcode';

    protected const string SERVER_ATTRIBUTE = 'hypervel.websocket.server';

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

    protected ?CounterInterface $messages = null;

    protected ?UpDownCounterInterface $activeConnections = null;

    /**
     * Create WebSocket instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected LogContextScopeFactory $logContextScopes,
        protected OperationOrigin $origins,
    ) {
    }

    /**
     * Register WebSocket listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.websocket');
        }

        $meter = null;

        if ($this->metricEnabled(self::DURATION_METRIC)) {
            $meter = $this->meterProvider->getMeter('hypervel.websocket');
            $this->duration = $meter->createHistogram(
                self::DURATION_METRIC,
                's',
                'Duration of WebSocket message handling.',
                ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
            );
        }

        if ($this->metricEnabled(self::MESSAGES_METRIC)) {
            $meter ??= $this->meterProvider->getMeter('hypervel.websocket');
            $this->messages = $meter->createCounter(
                self::MESSAGES_METRIC,
                '{message}',
                'Number of handled WebSocket messages.',
            );
        }

        if ($this->metricEnabled(self::ACTIVE_CONNECTIONS_METRIC)) {
            $meter ??= $this->meterProvider->getMeter('hypervel.websocket');
            $this->activeConnections = $meter->createUpDownCounter(
                self::ACTIVE_CONNECTIONS_METRIC,
                '{connection}',
                'Number of active WebSocket connections.',
            );
        }

        $messageLifecycle = $this->tracer !== null || $this->duration !== null;

        if ($messageLifecycle) {
            $this->events->listen(MessageReceived::class, function (MessageReceived $event): void {
                $this->start($event);
            });
        }

        if ($messageLifecycle || $this->messages !== null) {
            $this->events->listen(MessageHandled::class, function (MessageHandled $event): void {
                $this->finish($event);
            });
        }

        if ($this->activeConnections !== null) {
            $this->events->listen(ConnectionOpened::class, function (ConnectionOpened $event): void {
                $this->activeConnections?->add(1, [self::SERVER_ATTRIBUTE => $event->server]);
            });
            $this->events->listen(ConnectionClosed::class, function (ConnectionClosed $event): void {
                $this->activeConnections?->add(-1, [self::SERVER_ATTRIBUTE => $event->server]);
            });
        }
    }

    /**
     * Start WebSocket message telemetry.
     */
    protected function start(MessageReceived $event): void
    {
        $startedAt = $this->clock->now();
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;
        $scope = null;
        $logContextScope = null;
        $attributes = $this->messageAttributes($event->frame, $event->server);

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder('websocket.message')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $this->origins->withOrigin(
                $span->storeInContext($parent),
                OperationOrigin::WEBSOCKET,
            );
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());
        }

        /** @var list<WebSocketTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $states[] = new WebSocketTelemetryState(
            $event->frame,
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
     * Complete WebSocket message telemetry.
     */
    protected function finish(MessageHandled $event): void
    {
        if ($event->exception instanceof CanceledException) {
            return;
        }

        $state = $this->takeState($event->frame);

        if ($state === null && $this->messages === null) {
            return;
        }

        $finishedAt = $state === null ? 0 : $this->clock->now();
        $spanRecording = $state?->span?->isRecording() === true;
        $attributes = $state->attributes ?? [];

        if ($spanRecording || ($state !== null && $this->duration !== null) || $this->messages !== null) {
            if ($attributes === []) {
                $attributes = $this->messageAttributes($event->frame, $event->server);
            }

            $attributes['result'] = $event->exception === null ? 'success' : 'failure';

            if ($event->exception !== null) {
                $attributes[ErrorAttributes::ERROR_TYPE] = $event->exception::class;
            }
        }

        try {
            if ($spanRecording) {
                $state->span?->setAttribute('result', $attributes['result']);

                if ($event->exception !== null) {
                    $state->span?->recordException($event->exception);
                    $state->span?->setAttribute(ErrorAttributes::ERROR_TYPE, $event->exception::class);
                    $state->span?->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            if ($state !== null) {
                $this->duration?->record(
                    ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
                    $attributes,
                    $state->context,
                );
            }

            $this->messages?->add(1, $attributes, $state->context ?? Context::getCurrent());
        } finally {
            $state?->logContextScope?->close();
            $state?->scope?->detach();
            $state?->span?->end($finishedAt);
        }
    }

    /**
     * Remove and return the matching top WebSocket message state.
     */
    protected function takeState(Frame $frame): ?WebSocketTelemetryState
    {
        /** @var list<WebSocketTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $last = array_key_last($states);

        if ($last === null || $states[$last]->frame !== $frame) {
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
     * Return bounded WebSocket message attributes.
     *
     * @return array<string, int|string>
     */
    protected function messageAttributes(Frame $frame, string $server): array
    {
        return [
            self::OPCODE_ATTRIBUTE => $frame->opcode,
            self::SERVER_ATTRIBUTE => $server,
        ];
    }
}
