<?php

declare(strict_types=1);

namespace Hypervel\Cache\RateLimiting;

class Unlimited extends GlobalLimit
{
    /**
     * Create a new limit instance.
     */
    public function __construct()
    {
        parent::__construct(PHP_INT_MAX);
    }
}
