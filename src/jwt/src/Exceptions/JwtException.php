<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Exceptions;

use Exception;

class JwtException extends Exception
{
    protected $message = 'An error occurred';
}
