<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Stringable;
use UnitEnum;

use function Hypervel\Support\enum_value;

final readonly class Backoff
{
    /**
     * Create a new exponential backoff policy.
     */
    private function __construct(
        public int $after,
        public int $initialDelay,
        public int $maxDelay,
        public int $resetAfter,
        public string $key = '',
    ) {
        $this->validate();
    }

    /**
     * Create a new exponential backoff policy.
     */
    public static function exponential(
        int $after = 1,
        int $initialDelay = 1,
        int $maxDelay = 60,
        int $resetAfter = 3600,
    ): self {
        return new self($after, $initialDelay, $maxDelay, $resetAfter);
    }

    /**
     * Set the key of the backoff policy.
     */
    public function by(Stringable|UnitEnum|string|int|null $key): self
    {
        if ($key instanceof UnitEnum) {
            $key = enum_value($key);
        }

        return new self(
            $this->after,
            $this->initialDelay,
            $this->maxDelay,
            $this->resetAfter,
            $key === null ? '' : (string) $key,
        );
    }

    /**
     * Validate the backoff parameters.
     */
    private function validate(): void
    {
        foreach ([
            'failure threshold' => $this->after,
            'initial delay' => $this->initialDelay,
            'maximum delay' => $this->maxDelay,
            'reset interval' => $this->resetAfter,
        ] as $name => $value) {
            if ($value < 1 || $value > AdmissionPolicy::MAX_INTEGER) {
                throw new InvalidRateLimitException(sprintf(
                    'The backoff %s must be between 1 and %d.',
                    $name,
                    AdmissionPolicy::MAX_INTEGER,
                ));
            }
        }

        if ($this->initialDelay > $this->maxDelay) {
            throw new InvalidRateLimitException(
                'The backoff initial delay may not exceed its maximum delay.'
            );
        }

        if ($this->resetAfter < $this->maxDelay) {
            throw new InvalidRateLimitException(
                'The backoff reset interval must be at least its maximum delay.'
            );
        }

        if ($this->maxDelay > intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000)
            || $this->resetAfter > intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000)) {
            throw new InvalidRateLimitException(
                'The backoff duration exceeds the maximum supported duration.'
            );
        }
    }
}
