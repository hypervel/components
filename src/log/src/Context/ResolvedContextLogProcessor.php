<?php

declare(strict_types=1);

namespace Hypervel\Log\Context;

use Closure;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Wraps the resolved context log processor in a known type.
 *
 * This allows LogManager::createStackDriver() to reliably filter out
 * context processors from constituent channels regardless of how the
 * processor was bound (class, closure, or custom callable).
 */
final class ResolvedContextLogProcessor implements ProcessorInterface
{
    private readonly Closure $processor;

    public function __construct(callable $processor)
    {
        $this->processor = Closure::fromCallable($processor);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return ($this->processor)($record);
    }
}
