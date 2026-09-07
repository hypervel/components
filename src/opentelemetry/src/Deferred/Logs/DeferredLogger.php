<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Logs;

use Hypervel\OpenTelemetry\Deferred\InstrumentationScope;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Logs\NoopLogger;
use OpenTelemetry\Context\ContextInterface;
use Override;

/**
 * Keep a pre-fork logger handle usable after a worker provider is bound.
 *
 * @internal
 */
class DeferredLogger implements LoggerInterface
{
    protected ?LoggerInterface $delegate = null;

    /**
     * Create a deferred logger.
     */
    public function __construct(protected readonly InstrumentationScope $instrumentationScope)
    {
    }

    /**
     * Bind this handle to a logger from the given provider.
     */
    public function bind(LoggerProviderInterface $provider): void
    {
        $this->delegate = $provider->getLogger(
            $this->instrumentationScope->name,
            $this->instrumentationScope->version,
            $this->instrumentationScope->schemaUrl,
            $this->instrumentationScope->attributes,
        );
    }

    /**
     * Unbind this handle from its worker logger.
     */
    public function unbind(): void
    {
        $this->delegate = null;
    }

    /**
     * Emit a legacy log record through the current logger.
     */
    #[Override]
    public function emit(LogRecord $logRecord): void
    {
        $this->delegate?->emit($logRecord);
    }

    /**
     * Create a log-record builder from the current logger.
     */
    #[Override]
    public function logRecordBuilder(): LogRecordBuilderInterface
    {
        return ($this->delegate ?? NoopLogger::getInstance())->logRecordBuilder();
    }

    /**
     * Determine whether the current logger is enabled for a record.
     */
    #[Override]
    public function isEnabled(
        ?ContextInterface $context = null,
        ?int $severityNumber = null,
        ?string $eventName = null,
    ): bool {
        return $this->delegate?->isEnabled($context, $severityNumber, $eventName) ?? false;
    }
}
