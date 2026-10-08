<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Throwable;

class RateLimitedException extends AiException implements FailoverableException
{
    /**
     * Create a rate limit exception for the provider.
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'Application rate limited by AI provider [' . $provider . '].',
            $code,
            $previous
        );
    }
}
