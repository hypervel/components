<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Exceptions;

use Hypervel\Validation\ValidationException;

class InvalidPasskeyException extends ValidationException
{
    /**
     * Create a new invalid passkey exception.
     *
     * @param null|array<array-key, mixed>|string $message
     */
    public static function make(array|string|null $message = null): static
    {
        return static::withMessages([
            'credential' => $message ?? __('Unable to register passkey. Please try again.'),
        ]);
    }
}
