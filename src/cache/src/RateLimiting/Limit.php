<?php

declare(strict_types=1);

namespace Hypervel\Cache\RateLimiting;

class Limit
{
    /**
     * The rate limit signature key.
     */
    public mixed $key;

    /**
     * The maximum number of attempts allowed within the given number of seconds.
     */
    public int $maxAttempts;

    /**
     * The number of seconds until the rate limit is reset.
     */
    public int $decaySeconds;

    /**
     * The after callback used to determine if the limiter should be hit.
     *
     * @var null|callable
     */
    public mixed $afterCallback = null;

    /**
     * The response generator callback.
     *
     * @var null|callable
     */
    public mixed $responseCallback = null;

    /**
     * Create a new limit instance.
     */
    public function __construct(mixed $key = '', int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
    }

    /**
     * Create a new rate limit.
     */
    public static function perSecond(int $maxAttempts, int $decaySeconds = 1): static
    {
        return new static('', $maxAttempts, $decaySeconds);
    }

    /**
     * Create a new rate limit.
     */
    public static function perMinute(int $maxAttempts, int $decayMinutes = 1): static
    {
        return new static('', $maxAttempts, 60 * $decayMinutes);
    }

    /**
     * Create a new rate limit using minutes as decay time.
     */
    public static function perMinutes(int $decayMinutes, int $maxAttempts): static
    {
        return new static('', $maxAttempts, 60 * $decayMinutes);
    }

    /**
     * Create a new rate limit using hours as decay time.
     */
    public static function perHour(int $maxAttempts, int $decayHours = 1): static
    {
        return new static('', $maxAttempts, 60 * 60 * $decayHours);
    }

    /**
     * Create a new rate limit using days as decay time.
     */
    public static function perDay(int $maxAttempts, int $decayDays = 1): static
    {
        return new static('', $maxAttempts, 60 * 60 * 24 * $decayDays);
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
    public function by(mixed $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Set the callback to determine if the limiter should be hit.
     */
    public function after(callable $callback): static
    {
        $this->afterCallback = $callback;

        return $this;
    }

    /**
     * Set the callback that should generate the response when the limit is exceeded.
     */
    public function response(callable $callback): static
    {
        $this->responseCallback = $callback;

        return $this;
    }

    /**
     * Get a potential fallback key for the limit.
     */
    public function fallbackKey(): string
    {
        $prefix = $this->key ? "{$this->key}:" : '';

        return "{$prefix}attempts:{$this->maxAttempts}:decay:{$this->decaySeconds}";
    }
}
