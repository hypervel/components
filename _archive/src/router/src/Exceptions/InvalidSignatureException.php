<?php

declare(strict_types=1);

namespace Hypervel\Router\Exceptions;

use Hypervel\HttpMessage\Exceptions\HttpException;

class InvalidSignatureException extends HttpException
{
    /**
     * Create a new exception instance.
     */
    public function __construct()
    {
        parent::__construct(403, 'Invalid signature.');
    }
}
