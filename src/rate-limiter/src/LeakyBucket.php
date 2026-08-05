<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;

readonly class LeakyBucket extends AdmissionPolicy
{
    /**
     * Create a new leaky-bucket limit.
     */
    public function __construct(
        public int $rate,
        public int $periodMicroseconds,
        public int $burst,
        string $key = '',
        int $cost = 1,
        bool $global = false,
        ?Closure $afterCallback = null,
        ?Closure $responseCallback = null,
    ) {
        static::ensurePositive('rate', $rate);
        static::ensurePositive('period', $periodMicroseconds);
        static::ensurePositive('burst', $burst);

        if ($rate > $periodMicroseconds) {
            throw new InvalidRateLimitException(
                'The leaky-bucket rate may not exceed its period in microseconds.'
            );
        }

        $emission = intdiv($periodMicroseconds, $rate)
            + ($periodMicroseconds % $rate === 0 ? 0 : 1);

        if ($burst > intdiv(self::MAX_INTEGER, $emission)) {
            throw new InvalidRateLimitException(
                'The leaky-bucket burst exceeds the maximum supported refill duration.'
            );
        }

        parent::__construct($key, $cost, $global, $afterCallback, $responseCallback);
    }

    /**
     * Create a new per-second leaky-bucket limit.
     */
    public static function perSecond(int $rate, int $decaySeconds = 1): static
    {
        return new static(
            $rate,
            static::multiply($decaySeconds, 1_000_000, 'decay seconds'),
            $rate,
        );
    }

    /**
     * Create a new per-minute leaky-bucket limit.
     */
    public static function perMinute(int $rate, int $decayMinutes = 1): static
    {
        return static::perSecond($rate, static::multiply($decayMinutes, 60, 'decay minutes'));
    }

    /**
     * Create a new leaky-bucket limit using minutes as the period.
     */
    public static function perMinutes(int $decayMinutes, int $rate): static
    {
        return static::perMinute($rate, $decayMinutes);
    }

    /**
     * Create a new per-hour leaky-bucket limit.
     */
    public static function perHour(int $rate, int $decayHours = 1): static
    {
        return static::perSecond($rate, static::multiply($decayHours, 3600, 'decay hours'));
    }

    /**
     * Create a new per-day leaky-bucket limit.
     */
    public static function perDay(int $rate, int $decayDays = 1): static
    {
        return static::perSecond($rate, static::multiply($decayDays, 86400, 'decay days'));
    }

    /**
     * Set the immediately available capacity.
     */
    public function burst(int $capacity): static
    {
        static::ensurePositive('burst', $capacity);

        return new static(
            rate: $this->rate,
            periodMicroseconds: $this->periodMicroseconds,
            burst: $capacity,
            key: $this->key,
            cost: $this->cost,
            global: $this->global,
            afterCallback: $this->afterCallback,
            responseCallback: $this->responseCallback,
        );
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
            rate: $this->rate,
            periodMicroseconds: $this->periodMicroseconds,
            burst: $this->burst,
            key: $key,
            cost: $cost,
            global: $global,
            afterCallback: $afterCallback,
            responseCallback: $responseCallback,
        );
    }
}
