<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Stringable;
use UnitEnum;

use function Hypervel\Support\enum_value;

abstract readonly class AdmissionPolicy
{
    /**
     * The largest integer represented exactly by every first-party store.
     */
    public const int MAX_INTEGER = 9_007_199_254_740_991;

    /**
     * Create a new admission policy.
     */
    public function __construct(
        public string $key = '',
        public int $cost = 1,
        public bool $global = false,
        public ?Closure $afterCallback = null,
        public ?Closure $responseCallback = null,
    ) {
        static::ensurePositive('cost', $cost);
    }

    /**
     * Set the key of the rate limit.
     */
    public function by(Stringable|UnitEnum|string|int|null $key): static
    {
        return $this->newInstance(
            $this->normalizeKey($key),
            $this->cost,
            $this->global,
            $this->afterCallback,
            $this->responseCallback,
        );
    }

    /**
     * Set the capacity consumed by each operation.
     */
    public function cost(int $cost): static
    {
        static::ensurePositive('cost', $cost);

        return $this->newInstance(
            $this->key,
            $cost,
            $this->global,
            $this->afterCallback,
            $this->responseCallback,
        );
    }

    // Laravel's separate GlobalLimit marker is replaced by this immutable
    // modifier so every admission policy can opt out of named key scoping.

    /**
     * Apply the policy without a named limiter scope.
     */
    public function globally(bool $global = true): static
    {
        return $this->newInstance(
            $this->key,
            $this->cost,
            $global,
            $this->afterCallback,
            $this->responseCallback,
        );
    }

    /**
     * Set the callback that determines whether the operation should be consumed.
     */
    public function after(callable $callback): static
    {
        return $this->newInstance(
            $this->key,
            $this->cost,
            $this->global,
            Closure::fromCallable($callback),
            $this->responseCallback,
        );
    }

    /**
     * Set the callback that generates a response when the limit is exceeded.
     */
    public function response(callable $callback): static
    {
        return $this->newInstance(
            $this->key,
            $this->cost,
            $this->global,
            $this->afterCallback,
            Closure::fromCallable($callback),
        );
    }

    /**
     * Create a copy with the given shared policy values.
     */
    abstract protected function newInstance(
        string $key,
        int $cost,
        bool $global,
        ?Closure $afterCallback,
        ?Closure $responseCallback,
    ): static;

    /**
     * Convert a caller key to its canonical string value.
     */
    protected function normalizeKey(Stringable|UnitEnum|string|int|null $key): string
    {
        if ($key instanceof UnitEnum) {
            $key = enum_value($key);
        }

        return $key === null ? '' : (string) $key;
    }

    /**
     * Validate a positive shared integer.
     */
    protected static function ensurePositive(string $name, int $value): void
    {
        if ($value < 1 || $value > self::MAX_INTEGER) {
            throw new InvalidRateLimitException(sprintf(
                'The rate limit %s must be between 1 and %d.',
                $name,
                self::MAX_INTEGER,
            ));
        }
    }

    /**
     * Multiply positive rate limit values without losing integer precision.
     */
    protected static function multiply(int $value, int $multiplier, string $name): int
    {
        static::ensurePositive($name, $value);
        static::ensurePositive($name . ' multiplier', $multiplier);

        if ($value > intdiv(self::MAX_INTEGER, $multiplier)) {
            throw new InvalidRateLimitException(sprintf(
                'The rate limit %s exceeds the maximum supported duration.',
                $name,
            ));
        }

        return $value * $multiplier;
    }
}
