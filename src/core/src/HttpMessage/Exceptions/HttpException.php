<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Exceptions;

use Hyperf\HttpMessage\Exception\HttpException as BaseHttpException;
use Throwable;

class HttpException extends BaseHttpException
{
    public function __construct(
        int $statusCode,
        $message = '',
        $code = 0,
        ?Throwable $previous = null,
        protected array $headers = []
    ) {
        parent::__construct($statusCode, $message, $code, $previous);
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
