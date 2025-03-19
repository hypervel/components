<?php

declare(strict_types=1);

namespace Hypervel\Queue\Exceptions;

use InvalidArgumentException;

class InvalidPayloadException extends InvalidArgumentException
{
    /**
     * The value that failed to decode.
     */
    public mixed $value;

    /**
     * Create a new exception instance.
     */
    public function __construct(?string $message = null, mixed $value = null)
    {
        parent::__construct($message ?: json_last_error());

        $this->value = $value;
    }
}
