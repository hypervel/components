<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class RoleException extends HttpException
{
    public function __construct(
        int $statusCode,
        string $message = '',
        ?Throwable $previous = null,
        array $headers = [],
        int $code = 0,
        protected array $roles = []
    ) {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    public function roles(): array
    {
        return $this->roles;
    }
}
