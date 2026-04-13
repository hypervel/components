<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Exceptions;

use RuntimeException;

/**
 * @internal
 */
class ApplicationNotAvailableException extends RuntimeException
{
    /**
     * Create a new exception for an unavailable application.
     */
    public static function make(?string $caller): static
    {
        return new static(sprintf('Application is not available to run [%s]', $caller ?? 'N/A'));
    }
}
