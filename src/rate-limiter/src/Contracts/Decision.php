<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Contracts;

interface Decision
{
    /**
     * Determine if the operation was allowed.
     */
    public function allowed(): bool;

    /**
     * Determine if the operation was denied.
     */
    public function denied(): bool;

    /**
     * Get the number of seconds until the operation may be retried.
     */
    public function retryAfter(): int;
}
