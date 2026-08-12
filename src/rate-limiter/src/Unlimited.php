<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;

final readonly class Unlimited extends AdmissionPolicy
{
    /**
     * Create a copy with the given shared policy values.
     */
    protected function newInstance(
        string $key,
        int $cost,
        bool $global,
        ?Closure $afterCallback,
        ?Closure $responseCallback,
    ): static {
        return new self($key, $cost, $global, $afterCallback, $responseCallback);
    }
}
