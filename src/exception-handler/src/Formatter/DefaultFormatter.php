<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Formatter;

use Throwable;

class DefaultFormatter implements FormatterInterface
{
    /**
     * Format the given throwable as a string.
     */
    public function format(Throwable $throwable): string
    {
        return (string) $throwable;
    }
}
