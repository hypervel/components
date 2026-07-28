<?php

declare(strict_types=1);

namespace Hypervel\Queue;

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
        parent::__construct($message ?? 'Unable to decode the queue job payload.');

        $this->value = $value;
    }
}
