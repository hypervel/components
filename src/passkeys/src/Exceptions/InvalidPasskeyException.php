<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Exceptions;

use Hypervel\Validation\ValidationException;

class InvalidPasskeyException extends ValidationException
{
    /**
     * Create a new invalid passkey exception.
     */
    public static function make(string $message = 'Unable to register passkey. Please try again.'): static
    {
        return static::withMessages([
            'credential' => __($message),
        ]);
    }
}
