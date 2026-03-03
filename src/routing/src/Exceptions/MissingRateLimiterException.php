<?php

declare(strict_types=1);

namespace Hypervel\Routing\Exceptions;

use Exception;

class MissingRateLimiterException extends Exception
{
    /**
     * Create a new exception for invalid named rate limiter.
     */
    public static function forLimiter(string $limiter): static
    {
        return new static("Rate limiter [{$limiter}] is not defined.");
    }

    /**
     * Create a new exception for an invalid rate limiter based on a model property.
     *
     * @param class-string $model
     */
    public static function forLimiterAndUser(string $limiter, string $model): static
    {
        return new static("Rate limiter [{$model}::{$limiter}] is not defined.");
    }
}
