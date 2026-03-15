<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Exceptions;

use RuntimeException;

class SocketAcceptException extends RuntimeException
{
    /**
     * Determine if the exception was caused by a socket timeout.
     */
    public function isTimeout(): bool
    {
        return $this->getCode() === SOCKET_ETIMEDOUT;
    }
}
