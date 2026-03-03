<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Exceptions;

use Throwable;

class ThrottleRequestsException extends TooManyRequestsHttpException
{
    /**
     * Create a new throttle requests exception instance.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $headers = [],
    ) {
        parent::__construct(null, $message, $code, $previous, $headers);
    }
}
