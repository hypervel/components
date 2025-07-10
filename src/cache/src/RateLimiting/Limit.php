<?php

declare(strict_types=1);

namespace Hypervel\Cache\RateLimiting;

use Closure;

class Limit
{
    /**
     * The rate limit signature key.
     */
    public string $key;

    /**
     * The maximum number of attempts allowed within the given number of minutes.
     */
    public int $maxAttempts;

    /**
     * The number of minutes until the rate limit is reset.
     */
    public int $decayMinutes;

    /**
     * The response generator callback.
     */
    public ?Closure $responseCallback = null;

    /**
     * Create a new limit instance.
     */
    public function __construct(string $key = '', int $maxAttempts = 60, int $decayMinutes = 1)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }

    /**
     * Create a new rate limit.
     */
    public static function perMinute(int $maxAttempts): static
    {
        return new static('', $maxAttempts);
    }

    /**
     * Create a new rate limit using hours as decay time.
     */
    public static function perHour(int $maxAttempts, int $decayHours = 1): static
    {
        return new static('', $maxAttempts, 60 * $decayHours);
    }

    /**
     * Create a new rate limit using days as decay time.
     */
    public static function perDay(int $maxAttempts, int $decayDays = 1): static
    {
        return new static('', $maxAttempts, 60 * 24 * $decayDays);
    }

    /**
     * Create a new unlimited rate limit.
     */
    public static function none(): Unlimited
    {
        return new Unlimited();
    }

    /**
     * Set the key of the rate limit.
     */
    public function by(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Set the callback that should generate the response when the limit is exceeded.
     */
    public function response(Closure $callback): static
    {
        $this->responseCallback = $callback;

        return $this;
    }
}
