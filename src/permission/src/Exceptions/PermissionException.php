<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Hypervel\HttpMessage\Exceptions\HttpException;
use Throwable;

class PermissionException extends HttpException
{
    public function __construct(
        int $statusCode,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected array $headers = [],
        protected array $permissions = []
    ) {
        parent::__construct($statusCode, $message, $code, $previous);
    }

    public function permissions(): array
    {
        return $this->permissions;
    }
}
