<?php

declare(strict_types=1);

namespace Hypervel\Engine\Exceptions;

class CoroutineCreateException extends RuntimeException
{
    /**
     * Create an exception from Swoole's last error.
     */
    public static function fromLastError(): static
    {
        $code = swoole_last_error();

        return new static(
            sprintf('Unable to create coroutine: %s', swoole_strerror($code)),
            $code,
        );
    }
}
