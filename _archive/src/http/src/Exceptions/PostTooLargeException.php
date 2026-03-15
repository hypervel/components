<?php

declare(strict_types=1);

namespace Hypervel\Http\Exceptions;

use Hypervel\HttpMessage\Exceptions\HttpException;
use Throwable;

class PostTooLargeException extends HttpException
{
    /**
     * Create a new "post too large" exception instance.
     */
    public function __construct(string $message = '', ?Throwable $previous = null, array $headers = [], int $code = 0)
    {
        parent::__construct(413, $message, $code, $previous, $headers);
    }
}
