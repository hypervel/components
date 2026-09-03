<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\QueueConsumerState;
use Hypervel\OpenTelemetry\Support\QueueProducerState;
use Hypervel\OpenTelemetry\Support\QueueProducerStateStore;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Queue\Queue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\TimeoutExceededException;
use JsonException;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;
use WeakMap;

class QueueInstrumentation extends AbstractInstrumentation
{
    use LogsMessagesTrait;

    protected const string SENT_MESSAGES_METRIC = 'messaging.client.sent.messages';

    protected const string SEND_DURATION_METRIC = 'messaging.client.operation.duration';

    protected const string CONSUMED_MESSAGES_METRIC = 'messaging.client.consumed.messages';

    protected const string PROCESS_DURATION_METRIC = 'messaging.process.duration';

    protected const string DEPTH_METRIC = 'hypervel.queue.jobs';

    protected const string CONNECTION_ATTRIBUTE = 'hypervel.queue.connection';

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

    protected ?CounterInterface $sentMessages = null;

    protected ?HistogramInterface $sendDuration = null;

    protected ?CounterInterface $consumedMessages = null;

    protected ?HistogramInterface $processDuration = null;

    protected bool $propagation = false;

    /** @var array<string, string> */
    protected array $connectionDrivers = [];

    /** @var array<string, true> */
    protected array $syncConnections = [];

    /** @var WeakMap<Job, QueueConsumerState> */
    protected WeakMap $consumerStates;

    /**
     * Create queue instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected Repository $config,
        protected QueueManager $queues,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected TextMapPropagatorInterface $propagator,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
        protected LogContextScopeFactory $logContextScopes,
    ) {
        $this->consumerStates = new WeakMap;
    }

    /**
     * Register queue listeners, propagation, and instruments.
     */
    protected function registerInstrumentation(): void
    {
        $this->propagation = $this->options->enabled('propagation');

        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.queue');
        }

        $this->registerMetrics();

        $producerMetrics = $this->sentMessages !== null || $this->sendDuration !== null;
        $producerTelemetry = $this->tracer !== null || $producerMetrics;
        $consumerCompletion = $this->tracer !== null
            || $this->propagation
            || $this->processDuration !== null;
        $consumerStart = $consumerCompletion || $this->consumedMessages !== null;

        if ($this->tracer !== null || $this->propagation || $producerTelemetry || $consumerStart) {
            $this->connectionDrivers = $this->connectionDrivers();

            foreach ($this->connectionDrivers as $connection => $driver) {
                if ($driver === 'sync') {
                    $this->syncConnections[$connection] = true;
                }
            }
        }

        if ($producerTelemetry || $this->propagation) {
            $this->events->listen(JobPayloadFinalizing::class, function (JobPayloadFinalizing $event): void {
                $this->finalizePayload($event);
            });
        }

        if ($producerTelemetry) {
            $this->events->listen(JobQueued::class, function (JobQueued $event): void {
                $this->finishProducer($event->payload);
            });
            $this->events->listen(JobQueueingFailed::class, function (JobQueueingFailed $event): void {
                $this->finishProducer($event->payload, $event->exception);
            });
        }

        if ($this->propagation) {
            $this->registerLocalPropagation();
        }

        if ($consumerStart) {
            $this->events->listen(JobProcessing::class, function (JobProcessing $event): void {
                $this->startConsumer($event);
            });
        }

        if ($consumerCompletion) {
            $this->events->listen(JobAttempted::class, function (JobAttempted $event): void {
                $this->finishConsumer($event);
            });

            if ($this->tracer !== null || $this->processDuration !== null) {
                $this->events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
                    $this->recordConsumerError($event->job, $event->exception);
                });
                $this->events->listen(JobFailed::class, function (JobFailed $event): void {
                    $this->recordConsumerError($event->job, $event->exception);
                });
                $this->events->listen(JobTimedOut::class, function (JobTimedOut $event): void {
                    $this->recordConsumerTimeout($event->job);
                });
            }
        }

        $this->registerDepthMetric();
    }

    /**
     * Create enabled queue metric instruments.
     */
    protected function registerMetrics(): void
    {
        $meter = null;

        if ($this->metricEnabled(self::SENT_MESSAGES_METRIC)) {
            $meter = $this->meterProvider->getMeter('hypervel.queue');
            $this->sentMessages = $meter->createCounter(
                self::SENT_MESSAGES_METRIC,
                '{message}',
                'The number of messages sent to a messaging system.',
            );
        }

        if ($this->metricEnabled(self::SEND_DURATION_METRIC)) {
            $meter ??= $this->meterProvider->getMeter('hypervel.queue');
            $this->sendDuration = $meter->createHistogram(
                self::SEND_DURATION_METRIC,
                's',
                'Duration of messaging send operations.',
                ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
            );
        }

        if ($this->metricEnabled(self::CONSUMED_MESSAGES_METRIC)) {
            $meter ??= $this->meterProvider->getMeter('hypervel.queue');
            $this->consumedMessages = $meter->createCounter(
                self::CONSUMED_MESSAGES_METRIC,
                '{message}',
                'The number of messages delivered to consumers.',
            );
        }

        if ($this->metricEnabled(self::PROCESS_DURATION_METRIC)) {
            $meter ??= $this->meterProvider->getMeter('hypervel.queue');
            $this->processDuration = $meter->createHistogram(
                self::PROCESS_DURATION_METRIC,
                's',
                'Duration of messaging process operations.',
                ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
            );
        }
    }

    /**
     * Inject propagation and start a persistent producer span.
     */
    protected function finalizePayload(JobPayloadFinalizing $event): void
    {
        $payload = null;
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;
        $uuid = null;
        $attributes = $this->tracer !== null || $this->sentMessages !== null || $this->sendDuration !== null
            ? $this->attributes(
                $event->connectionName,
                $event->queue,
                'enqueue',
                MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND,
            )
            : [];
        $startedAt = 0;
        $finalPayload = $event->payload;

        if ($this->tracer !== null) {
            $payload = $event->payload();
            $uuid = $payload['uuid'] ?? null;

            if (! is_string($uuid)) {
                throw new UnexpectedValueException(
                    'A persistent queue payload must contain a string UUID before OpenTelemetry finalization.',
                );
            }

            $startedAt = $this->clock->now();
            $span = $this->tracer
                ->spanBuilder($this->spanName('enqueue', $event->queue))
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $span->storeInContext($parent);
        } elseif ($this->sendDuration !== null) {
            $startedAt = $this->clock->now();
        }

        try {
            if ($this->propagation) {
                $payload ??= $event->payload();
                $this->propagator->inject($payload, ArrayAccessGetterSetter::getInstance(), $context);
                $finalPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            if ($span?->isRecording()) {
                $span->setAttribute(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID, $uuid);
                $span->setAttribute(
                    MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE,
                    strlen($finalPayload),
                );
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($span !== null) {
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR);
                $this->exceptionContexts->associate(
                    $exception,
                    $context,
                    $this->origins->resolve($context, $this->identity),
                );
                $span->end();
            }

            throw $exception;
        }

        if ($span !== null || $this->sentMessages !== null || $this->sendDuration !== null) {
            $state = new QueueProducerState($startedAt, $context, $span, $attributes);
            $store = QueueProducerStateStore::current();

            if ($span !== null) {
                $store->put($uuid, $finalPayload, $state);
            } else {
                $store->putTiming($finalPayload, $state);
            }
        }

        $event->payload = $finalPayload;
    }

    /**
     * Complete persistent producer telemetry.
     */
    protected function finishProducer(string $payload, ?Throwable $exception = null): void
    {
        $store = QueueProducerStateStore::current();
        $state = $store->take($payload);

        if ($state === null && $this->tracer !== null && ($uuid = $this->payloadUuid($payload)) !== null) {
            $state = $store->takeUuid($uuid);
        }

        if ($state === null) {
            return;
        }

        $finishedAt = $state->span === null && $this->sendDuration === null
            ? 0
            : $this->clock->now();
        $errorType = $exception === null ? null : $exception::class;
        $attributes = $errorType === null
            ? $state->attributes
            : $state->attributes + [ErrorAttributes::ERROR_TYPE => $errorType];

        try {
            if ($exception !== null && $state->span !== null) {
                $state->span->recordException($exception);
                $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $errorType);
                $state->span->setStatus(StatusCode::STATUS_ERROR);
                $this->exceptionContexts->associate(
                    $exception,
                    $state->context,
                    $this->origins->resolve($state->context, $this->identity),
                );
            }

            $this->sentMessages?->add(1, $attributes, $state->context);
            $this->sendDuration?->record(
                ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
                $attributes,
                $state->context,
            );
        } finally {
            $state->span?->end($finishedAt);
        }
    }

    /**
     * Return a framework UUID from a rewritten terminal payload.
     */
    protected function payloadUuid(string $payload): ?string
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && is_string($decoded['uuid'] ?? null)
            ? $decoded['uuid']
            : null;
    }

    /**
     * Register flat propagation for local asynchronous queue drivers.
     */
    protected function registerLocalPropagation(): void
    {
        $localConnections = array_filter(
            $this->connectionDrivers,
            static fn (string $driver): bool => $driver === 'background' || $driver === 'deferred',
        );

        if ($localConnections === []) {
            return;
        }

        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload) use ($localConnections): array {
            if (! isset($localConnections[$connection])) {
                return [];
            }

            $carrier = [];
            $this->propagator->inject(
                $carrier,
                ArrayAccessGetterSetter::getInstance(),
                Context::getCurrent(),
            );

            return $carrier;
        });
    }

    /**
     * Start consumer telemetry when a job reaches application processing.
     */
    protected function startConsumer(JobProcessing $event): void
    {
        $startedAt = $this->tracer === null && $this->processDuration === null
            ? 0
            : $this->clock->now();
        $ambient = Context::getCurrent();
        // Background and deferred also consume SyncJob instances, so configured driver identity is authoritative.
        $extractPropagation = $this->propagation && ! isset($this->syncConnections[$event->connectionName]);
        $producerContext = $extractPropagation
            ? $this->propagator->extract(
                $event->job->payload(),
                ArrayAccessGetterSetter::getInstance(),
                Context::getRoot(),
            )
            : Context::getRoot();
        $producerSpanContext = $extractPropagation
            ? Span::fromContext($producerContext)->getContext()
            : null;
        $context = $ambient;
        $span = null;
        $scope = null;
        $logContextScope = null;
        $queue = null;
        $attributes = [];

        if ($this->tracer !== null || $this->consumedMessages !== null || $this->processDuration !== null) {
            $queue = $event->job->getQueue();
            $attributes = $this->attributes(
                $event->connectionName,
                $queue,
                'process',
                MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_PROCESS,
            );
        }

        if ($this->tracer !== null) {
            $ambientSpanContext = Span::fromContext($ambient)->getContext();
            $parent = ! $ambientSpanContext->isValid() && $producerSpanContext?->isValid()
                ? $producerContext
                : $ambient;
            $builder = $this->tracer
                ->spanBuilder($this->spanName('process', $queue))
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes);

            if ($ambientSpanContext->isValid() && $producerSpanContext?->isValid()) {
                $builder->addLink($producerSpanContext);
            }

            $span = $builder->startSpan();
            $context = $this->origins->withOrigin($span->storeInContext($parent), OperationOrigin::JOB);
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());

            if ($span->isRecording()) {
                if (($uuid = $event->job->uuid()) !== null) {
                    $span->setAttribute(MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID, $uuid);
                }

                $span->setAttribute(
                    MessagingIncubatingAttributes::MESSAGING_MESSAGE_ENVELOPE_SIZE,
                    strlen($event->job->getRawBody()),
                );
            }
        } elseif ($producerSpanContext?->isValid()) {
            $context = $this->origins->withOrigin($producerContext, OperationOrigin::JOB);
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($producerSpanContext);
        }

        $this->consumedMessages?->add(1, $attributes, $context);

        if ($span !== null || $scope !== null || $this->processDuration !== null) {
            $this->consumerStates[$event->job] = new QueueConsumerState(
                $startedAt,
                $context,
                $span,
                $scope,
                $logContextScope,
                $attributes,
            );
        }
    }

    /**
     * Retain the first ordinary consumer failure.
     */
    protected function recordConsumerError(Job $job, Throwable $exception): void
    {
        $state = $this->consumerStates[$job] ?? null;

        if ($state === null || $state->completed || $state->errorType !== null) {
            return;
        }

        $state->exception = $exception;
        $state->errorType = $exception::class;
    }

    /**
     * Retain a timeout when no earlier failure explains the attempt.
     */
    protected function recordConsumerTimeout(Job $job): void
    {
        $state = $this->consumerStates[$job] ?? null;

        if ($state === null || $state->completed || $state->errorType !== null) {
            return;
        }

        $state->errorType = TimeoutExceededException::class;
    }

    /**
     * Complete consumer telemetry at the canonical attempt boundary.
     */
    protected function finishConsumer(JobAttempted $event): void
    {
        $state = $this->consumerStates[$event->job] ?? null;

        if ($state === null || $state->completed) {
            return;
        }

        $state->completed = true;
        unset($this->consumerStates[$event->job]);

        if ($state->errorType === null && $event->exception !== null) {
            $state->exception = $event->exception;
            $state->errorType = $event->exception::class;
        }

        $finishedAt = $state->span === null && $this->processDuration === null
            ? 0
            : $this->clock->now();
        $attributes = $state->errorType === null
            ? $state->attributes
            : $state->attributes + [ErrorAttributes::ERROR_TYPE => $state->errorType];

        try {
            if ($state->span?->isRecording()) {
                if ($state->exception !== null) {
                    $state->span->recordException($state->exception);
                }

                if ($state->errorType !== null) {
                    $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $state->errorType);
                    $state->span->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            if ($state->exception !== null && $state->span !== null) {
                $this->exceptionContexts->associate(
                    $state->exception,
                    $state->context,
                    $this->origins->resolve($state->context, $this->identity),
                );
            }

            $this->processDuration?->record(
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
     * Register collection-only queue depth in event worker zero.
     */
    protected function registerDepthMetric(): void
    {
        if (! $this->metricEnabled(self::DEPTH_METRIC)
            || $this->identity->type !== ProcessIdentity::EVENT
            || $this->identity->workerId !== 0
        ) {
            return;
        }

        /** @var array<string, list<string>> $targets */
        $targets = $this->options->get('depth_queues');
        $this->meterProvider
            ->getMeter('hypervel.queue')
            ->createObservableGauge(
                self::DEPTH_METRIC,
                '{job}',
                'The number of jobs currently in a queue.',
                [],
                function (ObserverInterface $observer) use ($targets): void {
                    foreach ($targets as $connection => $queues) {
                        foreach ($queues as $queue) {
                            try {
                                $size = $this->queues->connection($connection)->size($queue);
                            } catch (CanceledException $exception) {
                                throw $exception;
                            } catch (Throwable $exception) {
                                self::logError('OpenTelemetry queue depth collection failed.', [
                                    'exception' => $exception,
                                    'connection' => $connection,
                                    'queue' => $queue,
                                ]);

                                continue;
                            }

                            $observer->observe($size, [
                                self::CONNECTION_ATTRIBUTE => $connection,
                                MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => $queue,
                            ]);
                        }
                    }
                },
            );
    }

    /**
     * Return low-cardinality attributes shared by queue spans and metrics.
     *
     * @return array<string, string>
     */
    protected function attributes(
        string $connection,
        ?string $queue,
        string $operation,
        string $operationType,
    ): array {
        return array_filter([
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => $this->connectionDrivers[$connection] ?? 'unknown',
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => $queue,
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => $operation,
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => $operationType,
            self::CONNECTION_ATTRIBUTE => $connection,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * Return configured connection drivers by connection name.
     *
     * @return array<string, string>
     */
    protected function connectionDrivers(): array
    {
        $drivers = [];

        foreach ($this->config->array('queue.connections') as $connection => $configuration) {
            $driver = is_array($configuration) ? ($configuration['driver'] ?? null) : null;

            if (is_string($connection) && is_string($driver)) {
                $drivers[$connection] = match ($driver) {
                    'beanstalkd' => 'beanstalk',
                    'sqs' => MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_AWS_SQS,
                    default => $driver,
                };
            }
        }

        return $drivers;
    }

    /**
     * Return a queue operation span name.
     */
    protected function spanName(string $operation, ?string $queue): string
    {
        return $queue === null || $queue === '' ? $operation : "{$operation} {$queue}";
    }
}
