<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Hypervel\Context\CoroutineContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ContextLogProcessor implements ProcessorInterface
{
    /**
     * Add propagated context data to the log record's extra data.
     *
     * Only adds data from CoroutineContext::propagated()->all() (not hidden data).
     * Hidden context propagates to jobs but is intentionally excluded from logs.
     *
     * Uses CoroutineContext::hasPropagated() to avoid allocating an empty PropagatedContext
     * on every log write when the app never uses propagated context.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (! CoroutineContext::hasPropagated()) {
            return $record;
        }

        $propagated = CoroutineContext::propagated()->all();

        if ($propagated === []) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            ...$propagated,
        ]);
    }
}
