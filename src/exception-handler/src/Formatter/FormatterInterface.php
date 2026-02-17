<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Formatter;

use Throwable;

interface FormatterInterface
{
    /**
     * Format the given throwable as a string.
     */
    public function format(Throwable $throwable): string;
}
