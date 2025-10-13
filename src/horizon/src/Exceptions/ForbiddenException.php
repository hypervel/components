<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Exceptions;

use Hypervel\HttpMessage\Exceptions\HttpException;

class ForbiddenException extends HttpException
{
    /**
     * Create a new exception instance.
     */
    public static function make(): static
    {
        return new static(403);
    }
}
