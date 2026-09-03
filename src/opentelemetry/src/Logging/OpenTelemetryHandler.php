<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Logging;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord as ApiLogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SDK\Common\Attribute\AttributeValidator;
use Throwable;

class OpenTelemetryHandler extends AbstractHandler
{
    protected ?ExceptionHandler $exceptionHandler = null;

    /**
     * Create an OpenTelemetry log handler.
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected OpenTelemetryManager $manager,
        protected ExceptionContextRegistry $exceptionContexts,
        protected Container $container,
        protected AttributeValidator $attributeValidator,
        int|string|Level $level = Level::Debug,
    ) {
        parent::__construct($level);
    }

    /**
     * Determine whether the record should be handled.
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->manager->logsEnabled() && parent::isHandling($record);
    }

    /**
     * Emit a Monolog record through the OpenTelemetry logger.
     */
    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof Throwable && $this->isDirectlyRecorded($exception)) {
            return false;
        }

        $builder = $this->logger
            ->logRecordBuilder()
            ->setTimestamp(
                ((int) $record->datetime->format('U')) * ApiLogRecord::NANOS_PER_SECOND
                + ((int) $record->datetime->format('u')) * 1000,
            )
            ->setSeverityNumber(Severity::fromPsr3($record->level->toPsrLogLevel()))
            ->setSeverityText($record->level->getName())
            ->setBody($record->message);

        foreach ($record->context as $key => $value) {
            if ($key === 'exception'
                || ! is_string($key)
                || (is_array($value) && ! array_is_list($value))
                || ! $this->attributeValidator->validate($value)
            ) {
                continue;
            }

            $builder->setAttribute($key, $value);
        }

        if ($exception instanceof Throwable) {
            $builder->setException($exception);
        }

        $builder->emit();

        return false;
    }

    /**
     * Determine whether direct exception capture already emitted the record.
     */
    protected function isDirectlyRecorded(Throwable $exception): bool
    {
        if (! $this->exceptionContexts->hasRecorder()) {
            return false;
        }

        $handler = $this->exceptionHandler ??= $this->container->make(ExceptionHandler::class);

        return method_exists($handler, 'isReporting') && $handler->isReporting($exception);
    }
}
