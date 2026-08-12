<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;

readonly class Limit extends AdmissionPolicy
{
    /**
     * Create a new fixed-window limit.
     */
    public function __construct(
        public int $maxAttempts = 60,
        public int $decaySeconds = 60,
        string $key = '',
        int $cost = 1,
        bool $global = false,
        ?Closure $afterCallback = null,
        ?Closure $responseCallback = null,
    ) {
        static::ensurePositive('maximum attempts', $maxAttempts);
        // Every store converts the window to microseconds, so validate that conversion here.
        static::multiply($decaySeconds, 1_000_000, 'decay seconds');

        parent::__construct($key, $cost, $global, $afterCallback, $responseCallback);
    }

    /**
     * Create a new per-second rate limit.
     */
    public static function perSecond(int $maxAttempts, int $decaySeconds = 1): static
    {
        return new static($maxAttempts, $decaySeconds);
    }

    /**
     * Create a new per-minute rate limit.
     */
    public static function perMinute(int $maxAttempts, int $decayMinutes = 1): static
    {
        $decayMicroseconds = static::multiply($decayMinutes, 60_000_000, 'decay minutes');

        return new static($maxAttempts, intdiv($decayMicroseconds, 1_000_000));
    }

    /**
     * Create a new rate limit using minutes as the decay time.
     */
    public static function perMinutes(int $decayMinutes, int $maxAttempts): static
    {
        return static::perMinute($maxAttempts, $decayMinutes);
    }

    /**
     * Create a new per-hour rate limit.
     */
    public static function perHour(int $maxAttempts, int $decayHours = 1): static
    {
        $decayMicroseconds = static::multiply($decayHours, 3_600_000_000, 'decay hours');

        return new static($maxAttempts, intdiv($decayMicroseconds, 1_000_000));
    }

    /**
     * Create a new per-day rate limit.
     */
    public static function perDay(int $maxAttempts, int $decayDays = 1): static
    {
        $decayMicroseconds = static::multiply($decayDays, 86_400_000_000, 'decay days');

        return new static($maxAttempts, intdiv($decayMicroseconds, 1_000_000));
    }

    /**
     * Create a new unlimited rate limit.
     */
    public static function none(): Unlimited
    {
        return new Unlimited;
    }

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
        return new static(
            maxAttempts: $this->maxAttempts,
            decaySeconds: $this->decaySeconds,
            key: $key,
            cost: $cost,
            global: $global,
            afterCallback: $afterCallback,
            responseCallback: $responseCallback,
        );
    }
}
