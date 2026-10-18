<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Throwable;

class InsufficientCreditsException extends AiException implements FailoverableException
{
    /**
     * Create an insufficient credits exception for the provider.
     */
    public static function forProvider(string $provider, int $code = 0, ?Throwable $previous = null): self
    {
        return new self(
            'AI provider [' . $provider . '] has insufficient credits or quota.',
            $code,
            $previous
        );
    }
}
