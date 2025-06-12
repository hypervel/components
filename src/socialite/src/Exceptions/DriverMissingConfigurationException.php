<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Exceptions;

use InvalidArgumentException;

class DriverMissingConfigurationException extends InvalidArgumentException
{
    /**
     * Create a new exception for a missing configuration.
     *
     * @param array<int, string> $keys
     */
    public static function make(string $provider, array $keys): static
    {
        /* @phpstan-ignore new.static */
        return new static('Missing required configuration keys [' . implode(', ', $keys) . "] for [{$provider}] OAuth provider.");
    }
}
