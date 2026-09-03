<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\QueryFailed;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
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
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use Throwable;

class DatabaseInstrumentation extends AbstractInstrumentation
{
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

    protected const string CONNECTION_ROLE_ATTRIBUTE = 'hypervel.db.connection.role';

    protected ?TracerInterface $tracer = null;

    protected ?HistogramInterface $duration = null;

    protected bool $queryText = false;

    protected ?int $queryTextMaxLength = null;

    /**
     * Create database instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
    ) {
    }

    /**
     * Register database listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.database');
            $this->queryText = $this->options->enabled('query_text');
            /** @var null|int $queryTextMaxLength */
            $queryTextMaxLength = $this->options->get('query_text_max_length');
            $this->queryTextMaxLength = $queryTextMaxLength;
        }

        if ($this->metricEnabled(DbMetrics::DB_CLIENT_OPERATION_DURATION)) {
            $this->duration = $this->meterProvider
                ->getMeter('hypervel.database')
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

        $this->events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->queryExecuted($event);
        });
        $this->events->listen(QueryFailed::class, function (QueryFailed $event): void {
            $this->queryFailed($event);
        });
    }

    /**
     * Record a completed database query.
     */
    protected function queryExecuted(QueryExecuted $event): void
    {
        if ($event->time === null) {
            return;
        }

        $this->recordQuery(
            $event->sql,
            $event->time,
            $event->connection,
            $event->readWriteType,
        );
    }

    /**
     * Record a failed database query.
     */
    protected function queryFailed(QueryFailed $event): void
    {
        $this->recordQuery(
            $event->sql,
            $event->time,
            $event->connection,
            $event->readWriteType,
            $event->exception,
        );
    }

    /**
     * Record one completed database operation.
     */
    protected function recordQuery(
        string $query,
        float $elapsedMilliseconds,
        Connection $connection,
        ?string $connectionRole,
        ?Throwable $exception = null,
    ): void {
        $finishedAt = $startedAt = 0;

        if ($this->tracer !== null) {
            $finishedAt = $this->clock->now();
            $startedAt = $finishedAt - (int) round(
                $elapsedMilliseconds * ClockInterface::NANOS_PER_MILLISECOND,
            );
        }
        $operation = $this->operation($query);
        $errorType = $exception === null ? null : $this->errorType($exception);
        $attributes = $this->attributes($connection, $connectionRole, $operation, $errorType);
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder($this->spanName($operation, $attributes))
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $span->storeInContext($parent);

            if ($span->isRecording()) {
                if ($this->queryText) {
                    $span->setAttribute(DbAttributes::DB_QUERY_TEXT, $this->queryText($query));
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
     * @return array<string, int|string>
     */
    protected function attributes(
        Connection $connection,
        ?string $connectionRole,
        ?string $operation,
        ?string $errorType,
    ): array {
        $address = $this->serverAddress($connection, $connectionRole);
        $port = $address === null ? null : $connection->getConfig('port');

        return array_filter([
            DbAttributes::DB_SYSTEM_NAME => $this->systemName($connection->getDriverName()),
            DbAttributes::DB_NAMESPACE => $connection->getDatabaseName() ?: null,
            DbAttributes::DB_OPERATION_NAME => $operation,
            ServerAttributes::SERVER_ADDRESS => $address,
            ServerAttributes::SERVER_PORT => is_int($port) && $port > 0 ? $port : null,
            self::CONNECTION_ROLE_ATTRIBUTE => $connectionRole,
            ErrorAttributes::ERROR_TYPE => $errorType,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Return the database operation when the query has one safe leading statement.
     */
    protected function operation(string $query): ?string
    {
        $query = trim($query);

        if (str_ends_with($query, ';')) {
            $query = trim(substr($query, 0, -1));
        }

        if (str_contains($query, ';')
            || preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/i', $query, $matches) !== 1
        ) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    /**
     * Return the bounded query template.
     */
    protected function queryText(string $query): string
    {
        return $this->queryTextMaxLength === null
            ? $query
            : mb_substr($query, 0, $this->queryTextMaxLength, 'UTF-8');
    }

    /**
     * Return the semantic database system name for a Hypervel driver.
     */
    protected function systemName(string $driver): string
    {
        return match ($driver) {
            'mysql' => DbAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
            'mariadb' => DbAttributes::DB_SYSTEM_NAME_VALUE_MARIADB,
            'pgsql' => DbAttributes::DB_SYSTEM_NAME_VALUE_POSTGRESQL,
            'sqlite' => DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_SQLITE,
            default => $driver,
        };
    }

    /**
     * Return the configured server address when it identifies the queried endpoint.
     */
    protected function serverAddress(Connection $connection, ?string $connectionRole): ?string
    {
        if ($connectionRole === 'read'
            && $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY) !== 'read'
        ) {
            return null;
        }

        $socket = $connection->getConfig('unix_socket');

        if (is_string($socket) && $socket !== '') {
            return $socket;
        }

        $host = $connection->getConfig('host');

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Return the canonical error type for a failed operation.
     */
    protected function errorType(Throwable $exception): string
    {
        return ($exception->getPrevious() ?? $exception)::class;
    }

    /**
     * Return the semantic span name for a database operation.
     *
     * @param array<string, int|string> $attributes
     */
    protected function spanName(?string $operation, array $attributes): string
    {
        $target = $attributes[DbAttributes::DB_NAMESPACE]
            ?? $attributes[ServerAttributes::SERVER_ADDRESS]
            ?? null;

        if ($operation !== null && $target !== null) {
            return "{$operation} {$target}";
        }

        return $operation
            ?? $target
            ?? $attributes[DbAttributes::DB_SYSTEM_NAME];
    }
}
