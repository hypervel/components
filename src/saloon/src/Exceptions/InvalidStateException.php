<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

use Throwable;

class InvalidStateException extends SaloonException
{
    /**
     * Create an invalid OAuth state exception.
     */
    public function __construct(?string $message = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message ?? 'Invalid state.', $code, $previous);
    }
}
