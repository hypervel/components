<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\RedisQueryTextFormatter;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\RedisManager;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
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
use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;

class RedisInstrumentation extends AbstractInstrumentation
{
    use LogsMessagesTrait;

    protected const string CONNECTION_ATTRIBUTE = 'hypervel.redis.connection';

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

    protected bool $queryText = false;

    protected ?int $queryTextMaxLength = null;

    /**
     * Create Redis instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected OpenTelemetryManager $openTelemetry,
        protected RedisManager $redis,
        protected RedisQueryTextFormatter $queryTextFormatter,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
    ) {
    }

    /**
     * Register Redis listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.redis');
            $this->queryText = $this->options->enabled('query_text');
            /** @var null|int $queryTextMaxLength */
            $queryTextMaxLength = $this->options->get('query_text_max_length');
            $this->queryTextMaxLength = $queryTextMaxLength;
        }

        if ($this->metricEnabled(DbMetrics::DB_CLIENT_OPERATION_DURATION)) {
            $this->duration = $this->meterProvider
                ->getMeter('hypervel.redis')
                ->createHistogram(
                    DbMetrics::DB_CLIENT_OPERATION_DURATION,
                    's',
                    'Duration of database client operations.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
        }

        if ($this->tracer === null && $this->duration === null) {
            return;
        }

        $this->redis->enableEvents();

        $this->events->listen(CommandExecuted::class, function (CommandExecuted $event): void {
            $this->commandExecuted($event);
        });
        $this->events->listen(CommandFailed::class, function (CommandFailed $event): void {
            $this->commandFailed($event);
        });
    }

    /**
     * Record a completed Redis command.
     */
    protected function commandExecuted(CommandExecuted $event): void
    {
        $this->recordCommand(
            $event->command,
            $event->parameters,
            $event->time,
            $event->connectionName,
        );
    }

    /**
     * Record a failed Redis command.
     */
    protected function commandFailed(CommandFailed $event): void
    {
        /** @var float $time */
        $time = $event->time;

        $this->recordCommand(
            $event->command,
            $event->parameters,
            $time,
            $event->connectionName,
            $event->exception,
        );
    }

    /**
     * Record one completed Redis operation.
     *
     * @param array<int, mixed> $parameters
     */
    protected function recordCommand(
        string $command,
        array $parameters,
        float $elapsedMilliseconds,
        string $connection,
        ?Throwable $exception = null,
    ): void {
        $finishedAt = $startedAt = 0;

        if ($this->tracer !== null) {
            $finishedAt = $this->clock->now();
            $startedAt = $finishedAt - (int) round(
                $elapsedMilliseconds * ClockInterface::NANOS_PER_MILLISECOND,
            );
        }
        $operation = strtoupper($command);
        $attributes = $this->attributes(
            $operation,
            $connection,
            $exception === null ? null : $exception::class,
        );
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder($operation)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $span->storeInContext($parent);

            if ($span->isRecording()) {
                if ($this->queryText) {
                    $queryText = $this->queryText(
                        $command,
                        $operation,
                        $parameters,
                        $connection,
                    );

                    if ($queryText !== null) {
                        $span->setAttribute(DbAttributes::DB_QUERY_TEXT, $queryText);
                    }
                }

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            if ($exception !== null) {
                $this->exceptionContexts->associate(
                    $exception,
                    $context,
                    $this->origins->resolve($context, $this->identity),
                );
            }
        }

        $this->duration?->record(
            $elapsedMilliseconds / ClockInterface::MILLIS_PER_SECOND,
            $attributes,
            $context,
        );
        $span?->end($finishedAt);
    }

    /**
     * Return low-cardinality attributes shared by spans and metrics.
     *
     * @return array<string, string>
     */
    protected function attributes(string $operation, string $connection, ?string $errorType): array
    {
        return array_filter([
            DbAttributes::DB_SYSTEM_NAME => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_REDIS,
            DbAttributes::DB_OPERATION_NAME => $operation,
            self::CONNECTION_ATTRIBUTE => $connection,
            ErrorAttributes::ERROR_TYPE => $errorType,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Resolve the bounded Redis query text.
     *
     * @param array<int, mixed> $parameters
     */
    protected function queryText(
        string $command,
        string $operation,
        array $parameters,
        string $connection,
    ): ?string {
        $resolver = $this->openTelemetry->redisQueryTextResolver();

        if ($resolver === null) {
            $queryText = $this->queryTextFormatter->format($operation, $parameters);
        } else {
            try {
                $queryText = $resolver($command, $parameters, $connection);

                if ($queryText !== null && ! is_string($queryText)) {
                    throw new UnexpectedValueException(
                        'The OpenTelemetry Redis query-text resolver must return a string or null.',
                    );
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                self::logError('OpenTelemetry Redis query-text resolution failed.', ['exception' => $exception]);

                return null;
            }
        }

        if ($queryText === null || $this->queryTextMaxLength === null) {
            return $queryText;
        }

        return mb_substr($queryText, 0, $this->queryTextMaxLength, 'UTF-8');
    }
}
