<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;

readonly class SlidingWindow extends AdmissionPolicy
{
    // floor(AdmissionPolicy::MAX_INTEGER / 1_000_000), matching the weight
    // scale in both CalculatesRateLimits and RedisStore's sliding-window script.
    private const int MAX_ATTEMPTS = 9_007_199_254;

    /**
     * Create a new sliding-window limit.
     */
    public function __construct(
        public int $maxAttempts = 60,
        public int $windowSeconds = 60,
        string $key = '',
        int $cost = 1,
        bool $global = false,
        ?Closure $afterCallback = null,
        ?Closure $responseCallback = null,
    ) {
        static::ensurePositive('maximum attempts', $maxAttempts);

        if ($maxAttempts > self::MAX_ATTEMPTS) {
            throw new InvalidRateLimitException(sprintf(
                'The sliding-window capacity may not exceed %d.',
                self::MAX_ATTEMPTS,
            ));
        }

        // Every store converts the two-window lifetime to microseconds, so
        // validate that conversion here.
        static::multiply($windowSeconds, 2_000_000, 'window seconds');

        parent::__construct($key, $cost, $global, $afterCallback, $responseCallback);
    }

    /**
     * Create a new per-second sliding-window limit.
     */
    public static function perSecond(int $maxAttempts, int $windowSeconds = 1): static
    {
        return new static($maxAttempts, $windowSeconds);
    }

    /**
     * Create a new per-minute sliding-window limit.
     */
    public static function perMinute(int $maxAttempts, int $windowMinutes = 1): static
    {
        $windowMicroseconds = static::multiply($windowMinutes, 120_000_000, 'window minutes');

        return new static($maxAttempts, intdiv($windowMicroseconds, 2_000_000));
    }

    /**
     * Create a new sliding-window limit using minutes as the window.
     */
    public static function perMinutes(int $windowMinutes, int $maxAttempts): static
    {
        return static::perMinute($maxAttempts, $windowMinutes);
    }

    /**
     * Create a new per-hour sliding-window limit.
     */
    public static function perHour(int $maxAttempts, int $windowHours = 1): static
    {
        $windowMicroseconds = static::multiply($windowHours, 7_200_000_000, 'window hours');

        return new static($maxAttempts, intdiv($windowMicroseconds, 2_000_000));
    }

    /**
     * Create a new per-day sliding-window limit.
     */
    public static function perDay(int $maxAttempts, int $windowDays = 1): static
    {
        $windowMicroseconds = static::multiply($windowDays, 172_800_000_000, 'window days');

        return new static($maxAttempts, intdiv($windowMicroseconds, 2_000_000));
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
            windowSeconds: $this->windowSeconds,
            key: $key,
            cost: $cost,
            global: $global,
            afterCallback: $afterCallback,
            responseCallback: $responseCallback,
        );
    }
}
