<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Exceptions;

use RuntimeException;

class SocketAcceptException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        private readonly bool $permanent = false,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * Determine if the socket error is permanent (peer closed the pipe).
     */
    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    /**
     * Determine if the exception was caused by a socket timeout.
     */
    public function isTimeout(): bool
    {
        return $this->getCode() === SOCKET_ETIMEDOUT;
    }
}
