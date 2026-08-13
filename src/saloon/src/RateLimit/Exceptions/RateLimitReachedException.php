<?php

declare(strict_types=1);

namespace Hypervel\Saloon\RateLimit\Exceptions;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Contracts\Decision;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\Saloon\Exceptions\SaloonException;

class RateLimitReachedException extends SaloonException
{
    /**
     * Create a rate limit exception.
     */
    public function __construct(
        protected readonly AdmissionPolicy|Cooldown $policy,
        protected readonly Decision $result,
    ) {
        parent::__construct($policy->key === ''
            ? 'The request rate limit has been reached.'
            : sprintf('The request rate limit [%s] has been reached.', $policy->key));
    }

    /**
     * Get the policy that denied the operation.
     */
    public function policy(): AdmissionPolicy|Cooldown
    {
        return $this->policy;
    }

    /**
     * Get the denial result.
     */
    public function result(): Decision
    {
        return $this->result;
    }
}
