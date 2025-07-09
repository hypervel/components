<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Hypervel\HttpMessage\Exceptions\HttpException;
use Throwable;

class RoleException extends HttpException
{
    public function __construct(
        int $statusCode,
        $message = '',
        $code = 0,
        ?Throwable $previous = null,
        protected array $headers = [],
        protected array $roles = []
    ) {
        parent::__construct($statusCode, $message, $code, $previous);
    }

    public function roles(): array
    {
        return $this->roles;
    }
}