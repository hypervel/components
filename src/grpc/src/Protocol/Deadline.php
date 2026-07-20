<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use Closure;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Deadline
{
    /**
     * @param Closure(): int $now
     */
    private function __construct(
        private ?int $absoluteNanoseconds,
        private Closure $now,
    ) {
    }

    /**
     * Create a deadline from a local timeout in seconds.
     */
    public static function fromTimeout(?float $seconds): self
    {
        $now = self::systemClock();

        if ($seconds === null) {
            return new self(null, $now);
        }

        if (! is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException(
                'The gRPC timeout must be a positive finite number of seconds.',
            );
        }

        $currentNanoseconds = $now();
        $duration = ceil($seconds * 1_000_000_000);

        if (! is_finite($duration)) {
            throw new InvalidArgumentException('The gRPC timeout exceeds the monotonic clock range.');
        }

        $durationNanoseconds = (int) $duration;

        if (
            $durationNanoseconds <= 0
            || $durationNanoseconds > PHP_INT_MAX - $currentNanoseconds
        ) {
            throw new InvalidArgumentException('The gRPC timeout exceeds the monotonic clock range.');
        }

        return new self($currentNanoseconds + $durationNanoseconds, $now);
    }

    /**
     * Create a saturated deadline from a peer timeout in seconds.
     */
    public static function fromPeerTimeout(float $seconds): self
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException(
                'The peer gRPC timeout must be a non-negative finite number of seconds.',
            );
        }

        $now = self::systemClock();
        $currentNanoseconds = $now();
        $maximumDuration = PHP_INT_MAX - $currentNanoseconds;
        $duration = ceil($seconds * 1_000_000_000);

        if (! is_finite($duration) || $duration >= $maximumDuration) {
            return new self(PHP_INT_MAX, $now);
        }

        return new self($currentNanoseconds + (int) $duration, $now);
    }

    /**
     * Create a deadline with a controlled monotonic clock.
     *
     * @param Closure(): int $now
     *
     * @internal
     */
    public static function usingClock(?int $absoluteNanoseconds, Closure $now): self
    {
        if ($absoluteNanoseconds !== null && $absoluteNanoseconds < 0) {
            throw new InvalidArgumentException('The absolute deadline cannot be negative.');
        }

        return new self($absoluteNanoseconds, $now);
    }

    /**
     * Return the remaining seconds.
     *
     * @phpstan-impure
     */
    public function remainingSeconds(): ?float
    {
        if ($this->absoluteNanoseconds === null) {
            return null;
        }

        return max(0, $this->absoluteNanoseconds - ($this->now)()) / 1_000_000_000;
    }

    /**
     * Determine whether the deadline has expired.
     *
     * @phpstan-impure
     */
    public function expired(): bool
    {
        return $this->absoluteNanoseconds !== null
            && ($this->now)() >= $this->absoluteNanoseconds;
    }

    /**
     * Return the absolute monotonic deadline.
     */
    public function absoluteNanoseconds(): ?int
    {
        return $this->absoluteNanoseconds;
    }

    /**
     * Return the remaining duration as a gRPC timeout header value.
     *
     * @phpstan-impure
     */
    public function encodedHeader(): ?string
    {
        if ($this->absoluteNanoseconds === null) {
            return null;
        }

        $remainingNanoseconds = $this->absoluteNanoseconds - ($this->now)();

        if ($remainingNanoseconds <= 0) {
            return null;
        }

        return Timeout::encode($remainingNanoseconds / 1_000_000_000);
    }

    /**
     * Return the system monotonic clock.
     *
     * @return Closure(): int
     */
    private static function systemClock(): Closure
    {
        return static fn (): int => hrtime(true);
    }
}
