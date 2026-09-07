<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Grpc\ClientGrpcOperation;
use Hypervel\Grpc\Contracts\GrpcOperationObserver;
use Hypervel\Grpc\GrpcOperation;
use Hypervel\Grpc\GrpcOperationResult;
use Hypervel\Grpc\GrpcOperationRunner;
use Hypervel\Grpc\ServerGrpcOperation;
use Hypervel\Grpc\StatusCode;
use Hypervel\OpenTelemetry\Context\GrpcMetadataSetter;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\GrpcTelemetryState;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use LogicException;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode as TraceStatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use Swoole\Coroutine\CanceledException;
use Throwable;

class GrpcInstrumentation extends AbstractInstrumentation implements GrpcOperationObserver
{
    protected const string CLIENT_DURATION_METRIC = 'rpc.client.call.duration';

    protected const string SERVER_DURATION_METRIC = 'rpc.server.call.duration';

    protected const string RPC_SYSTEM_ATTRIBUTE = 'rpc.system.name';

    protected const string RPC_METHOD_ATTRIBUTE = 'rpc.method';

    protected const string RPC_STATUS_ATTRIBUTE = 'rpc.response.status_code';

    protected const string RPC_SYSTEM_GRPC = 'grpc';

    protected const string RPC_METHOD_OTHER = '_OTHER';

    protected const string ATTEMPT_COUNT_ATTRIBUTE = 'hypervel.grpc.attempt_count';

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

    protected ?HistogramInterface $clientDuration = null;

    protected ?HistogramInterface $serverDuration = null;

    protected bool $serverMetricAddress = false;

    /**
     * Create gRPC instrumentation.
     */
    public function __construct(
        protected GrpcOperationRunner $operations,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected TextMapPropagatorInterface $propagator,
        protected GrpcMetadataSetter $metadataSetter,
        protected LogContextScopeFactory $logContextScopes,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
    ) {
    }

    /**
     * Register the logical gRPC operation observer and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.grpc');
        }

        if ($this->metricEnabled(self::CLIENT_DURATION_METRIC)) {
            $this->clientDuration = $this->meterProvider
                ->getMeter('hypervel.grpc')
                ->createHistogram(
                    self::CLIENT_DURATION_METRIC,
                    's',
                    'Duration of logical gRPC client calls.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
        }

        if ($this->metricEnabled(self::SERVER_DURATION_METRIC)) {
            $this->serverDuration = $this->meterProvider
                ->getMeter('hypervel.grpc')
                ->createHistogram(
                    self::SERVER_DURATION_METRIC,
                    's',
                    'Duration of logical gRPC server calls.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
            $this->serverMetricAddress = $this->options->enabled('server_metric_address');
        }

        if ($this->tracer !== null || $this->clientDuration !== null || $this->serverDuration !== null) {
            $this->operations->observe($this);
        }
    }

    /**
     * Start observing a logical gRPC operation.
     */
    public function starting(GrpcOperation $operation): ?GrpcTelemetryState
    {
        return match (true) {
            $operation instanceof ClientGrpcOperation => $this->tracer === null && $this->clientDuration === null
                ? null
                : $this->startClient($operation),
            $operation instanceof ServerGrpcOperation => $this->tracer === null && $this->serverDuration === null
                ? null
                : $this->startServer($operation),
            default => $this->unsupportedOperation($operation),
        };
    }

    /**
     * Finish observing a logical gRPC operation.
     */
    public function finished(
        GrpcOperation $operation,
        mixed $token,
        GrpcOperationResult $result,
    ): void {
        if ($token === null || $result->exception instanceof CanceledException) {
            return;
        }

        /** @var GrpcTelemetryState $state */
        $state = $token;
        $this->complete($operation, $state, $result);
    }

    /**
     * Start a logical client call.
     */
    protected function startClient(ClientGrpcOperation $operation): GrpcTelemetryState
    {
        $startedAt = $this->clock->now();
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;
        $attributes = $this->clientAttributes($operation);

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder($attributes[self::RPC_METHOD_ATTRIBUTE])
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $span->storeInContext($parent);
        }

        $state = new GrpcTelemetryState(
            $startedAt,
            $context,
            $span,
            null,
            null,
            $this->clientDuration === null ? [] : $attributes,
        );

        if ($span === null) {
            return $state;
        }

        try {
            $metadata = $operation->metadata();
            $this->propagator->inject($metadata, $this->metadataSetter, $context);
            $operation->withMetadata($metadata);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->complete(
                $operation,
                $state,
                new GrpcOperationResult(null, $exception, 0),
            );

            throw $exception;
        }

        return $state;
    }

    /**
     * Start a logical server call.
     */
    protected function startServer(ServerGrpcOperation $operation): GrpcTelemetryState
    {
        $startedAt = $this->clock->now();
        $parent = $this->tracer === null
            ? Context::getCurrent()
            : $this->propagator->extract(
                $operation->metadata,
                ArrayAccessGetterSetter::getInstance(),
            );
        $context = $parent;
        $span = null;
        $scope = null;
        $logContextScope = null;
        $sharedAttributes = $this->sharedAttributes($operation);
        $endpointAttributes = ($this->tracer !== null
            || ($this->serverDuration !== null && $this->serverMetricAddress))
            ? $this->serverEndpointAttributes($operation)
            : [];

        if ($this->tracer !== null) {
            $spanName = $sharedAttributes[self::RPC_METHOD_ATTRIBUTE];
            $span = $this->tracer
                ->spanBuilder($spanName === self::RPC_METHOD_OTHER ? self::RPC_SYSTEM_GRPC : $spanName)
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($sharedAttributes + $endpointAttributes)
                ->startSpan();
            $context = $this->origins->withOrigin(
                $span->storeInContext($parent),
                OperationOrigin::RPC,
            );
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());
        }

        $metricAttributes = $this->serverDuration === null
            ? []
            : $sharedAttributes + ($this->serverMetricAddress
                ? $endpointAttributes
                : []);

        return new GrpcTelemetryState(
            $startedAt,
            $context,
            $span,
            $scope,
            $logContextScope,
            $metricAttributes,
        );
    }

    /**
     * Complete one logical gRPC call.
     */
    protected function complete(
        GrpcOperation $operation,
        GrpcTelemetryState $state,
        GrpcOperationResult $result,
    ): void {
        $finishedAt = $this->clock->now();
        $duration = $this->duration($operation);
        $spanRecording = $state->span?->isRecording() === true;
        $statusName = null;
        $errorType = null;
        $metricAttributes = $state->metricAttributes;

        if ($spanRecording || $duration !== null) {
            $status = $result->status?->code();
            $statusName = $status === null ? null : $this->statusName($status);
            $errorType = $this->errorType($operation, $status, $statusName, $result->exception);

            if ($statusName !== null) {
                $metricAttributes[self::RPC_STATUS_ATTRIBUTE] = $statusName;
            }

            if ($errorType !== null) {
                $metricAttributes[ErrorAttributes::ERROR_TYPE] = $errorType;
            }
        }

        try {
            if ($spanRecording) {
                if ($statusName !== null) {
                    $state->span->setAttribute(self::RPC_STATUS_ATTRIBUTE, $statusName);
                }

                if ($operation instanceof ClientGrpcOperation) {
                    $state->span->setAttribute(self::ATTEMPT_COUNT_ATTRIBUTE, $result->attemptCount);
                }

                if ($result->exception !== null) {
                    $state->span->recordException($result->exception);
                }

                if ($errorType !== null) {
                    $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $errorType);
                    $state->span->setStatus(TraceStatusCode::STATUS_ERROR);
                }
            }

            if ($operation instanceof ClientGrpcOperation && $result->exception !== null && $state->span !== null) {
                $this->exceptionContexts->associate(
                    $result->exception,
                    $state->context,
                    $this->origins->resolve($state->context, $this->identity),
                );
            }

            $duration?->record(
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
     * Return shared logical RPC attributes.
     *
     * @return array<string, string>
     */
    protected function sharedAttributes(GrpcOperation $operation): array
    {
        return [
            self::RPC_SYSTEM_ATTRIBUTE => self::RPC_SYSTEM_GRPC,
            self::RPC_METHOD_ATTRIBUTE => $this->method($operation),
        ];
    }

    /**
     * Return logical client-call attributes.
     *
     * @return array<string, int|string>
     */
    protected function clientAttributes(ClientGrpcOperation $operation): array
    {
        return $this->sharedAttributes($operation) + [
            ServerAttributes::SERVER_ADDRESS => $operation->serverAddress,
            ServerAttributes::SERVER_PORT => $operation->serverPort,
        ];
    }

    /**
     * Return truthful configured server endpoint attributes.
     *
     * @return array<string, int|string>
     */
    protected function serverEndpointAttributes(ServerGrpcOperation $operation): array
    {
        $attributes = [ServerAttributes::SERVER_PORT => $operation->serverPort];

        if (! in_array($operation->serverAddress, ['', '0.0.0.0', '::'], true)) {
            $attributes[ServerAttributes::SERVER_ADDRESS] = $operation->serverAddress;
        }

        return $attributes;
    }

    /**
     * Return the recognized RPC method or its bounded fallback.
     */
    protected function method(GrpcOperation $operation): string
    {
        $method = $operation->serviceMethod();

        return $method === null
            ? self::RPC_METHOD_OTHER
            : "{$method->service}/{$method->method}";
    }

    /**
     * Return the enabled duration instrument for an operation.
     */
    protected function duration(GrpcOperation $operation): ?HistogramInterface
    {
        return match (true) {
            $operation instanceof ClientGrpcOperation => $this->clientDuration,
            $operation instanceof ServerGrpcOperation => $this->serverDuration,
            default => $this->unsupportedOperation($operation),
        };
    }

    /**
     * Return the semantic error type for a completed call.
     */
    protected function errorType(
        GrpcOperation $operation,
        ?StatusCode $status,
        ?string $statusName,
        ?Throwable $exception,
    ): ?string {
        $statusIsError = match (true) {
            $status === null => false,
            $operation instanceof ClientGrpcOperation => $status !== StatusCode::Ok,
            $operation instanceof ServerGrpcOperation => match ($status) {
                StatusCode::Unknown,
                StatusCode::DeadlineExceeded,
                StatusCode::Unimplemented,
                StatusCode::Internal,
                StatusCode::Unavailable,
                StatusCode::DataLoss => true,
                default => false,
            },
            default => $this->unsupportedOperation($operation),
        };

        if ($status !== null) {
            return $statusIsError ? $statusName : null;
        }

        return $exception === null ? null : $exception::class;
    }

    /**
     * Reject a gRPC operation type the instrumentation cannot classify.
     */
    protected function unsupportedOperation(GrpcOperation $operation): never
    {
        throw new LogicException(sprintf(
            'Unsupported gRPC operation type [%s].',
            $operation::class,
        ));
    }

    /**
     * Return the standard gRPC status name.
     */
    protected function statusName(StatusCode $status): string
    {
        return match ($status) {
            StatusCode::Ok => 'OK',
            StatusCode::Cancelled => 'CANCELLED',
            StatusCode::Unknown => 'UNKNOWN',
            StatusCode::InvalidArgument => 'INVALID_ARGUMENT',
            StatusCode::DeadlineExceeded => 'DEADLINE_EXCEEDED',
            StatusCode::NotFound => 'NOT_FOUND',
            StatusCode::AlreadyExists => 'ALREADY_EXISTS',
            StatusCode::PermissionDenied => 'PERMISSION_DENIED',
            StatusCode::ResourceExhausted => 'RESOURCE_EXHAUSTED',
            StatusCode::FailedPrecondition => 'FAILED_PRECONDITION',
            StatusCode::Aborted => 'ABORTED',
            StatusCode::OutOfRange => 'OUT_OF_RANGE',
            StatusCode::Unimplemented => 'UNIMPLEMENTED',
            StatusCode::Internal => 'INTERNAL',
            StatusCode::Unavailable => 'UNAVAILABLE',
            StatusCode::DataLoss => 'DATA_LOSS',
            StatusCode::Unauthenticated => 'UNAUTHENTICATED',
        };
    }
}
