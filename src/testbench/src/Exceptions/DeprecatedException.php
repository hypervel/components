<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Exceptions;

use PHPUnit\Util\Filter;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
class DeprecatedException extends PHPUnitErrorException
{
    /**
     * Convert exception to string.
     */
    public function __toString(): string
    {
        $stackTrace = Filter::stackTraceFromThrowableAsString($this);

        return \sprintf('%s' . PHP_EOL . PHP_EOL . '%s', $this->getMessage(), $stackTrace);
    }
}
