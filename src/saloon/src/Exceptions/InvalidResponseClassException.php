<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

use Hypervel\Saloon\Http\Response;

class InvalidResponseClassException extends SaloonException
{
    /**
     * Create an invalid response class exception.
     */
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? sprintf('The provided response class must exist and extend %s.', Response::class));
    }
}
