<?php

declare(strict_types=1);

namespace Hypervel\Cache\RateLimiting;

class GlobalLimit extends Limit
{
    /**
     * Create a new limit instance.
     */
    public function __construct(int $maxAttempts, int $decaySeconds = 60)
    {
        parent::__construct('', $maxAttempts, $decaySeconds);
    }
}
