<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use Closure;
use Hypervel\Saloon\Data\RetryPolicy;

trait HasRetryPolicy
{
    /**
     * The request retry policy.
     */
    protected ?RetryPolicy $retryPolicy = null;

    /**
     * Specify the number of times the request should be attempted.
     *
     * @param int|list<int> $times
     * @return $this
     */
    public function retry(
        array|int $times,
        Closure|int $sleepMilliseconds = 0,
        ?callable $when = null,
        bool $throw = true,
    ): static {
        $this->retryPolicy = new RetryPolicy($times, $sleepMilliseconds, $when, $throw);

        return $this;
    }

    /**
     * Get the request retry policy.
     */
    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy ??= new RetryPolicy;
    }
}
