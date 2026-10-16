<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Throwable;

class ProviderConnectionException extends AiException implements FailoverableException
{
    /**
     * Create a connection exception for the provider.
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'Could not connect to AI provider [' . $provider . '].',
            $code,
            $previous,
        );
    }
}
