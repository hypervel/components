<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use RuntimeException;
use Throwable;

class ParseException extends RuntimeException
{
    /**
     * Create a parse error with a location in the SQL input.
     */
    public function __construct(
        string $message,
        public readonly ?int $sqlLine = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $sqlLine === null ? $message : "{$message} at SQL line {$sqlLine}",
            0,
            $previous,
        );
    }
}
