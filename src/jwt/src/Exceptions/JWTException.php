<?php

declare(strict_types=1);

namespace Hypervel\JWT\Exceptions;

use Exception;

class JWTException extends Exception
{
    protected $message = 'An error occurred';
}
