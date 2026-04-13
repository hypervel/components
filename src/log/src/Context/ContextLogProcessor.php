<?php

declare(strict_types=1);

namespace Hypervel\Log\Context;

use Hypervel\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Monolog\LogRecord;

class ContextLogProcessor implements ContextLogProcessorContract
{
    /**
     * Add context data to the log record's extra data.
     *
     * Only adds data from Repository::all() (not hidden data).
     * Hidden context propagates to jobs but is intentionally excluded from logs.
     *
     * Uses Repository::hasInstance() to avoid allocating an empty Repository
     * on every log write when the app never uses context.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (! Repository::hasInstance()) {
            return $record;
        }

        $context = Repository::getInstance()->all();

        if ($context === []) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            ...$context,
        ]);
    }
}
