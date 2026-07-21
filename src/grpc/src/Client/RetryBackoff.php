<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * @internal
 */
final class RetryBackoff
{
    private int $retries = 0;

    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly Randomizer $randomizer = new Randomizer,
    ) {
    }

    /**
     * Return the next jittered retry delay in seconds.
     */
    public function nextDelay(?float $remainingSeconds = null): float
    {
        if (
            $remainingSeconds !== null
            && (! is_finite($remainingSeconds) || $remainingSeconds < 0)
        ) {
            throw new InvalidArgumentException(
                'The remaining gRPC deadline must be a non-negative finite number of seconds.',
            );
        }

        $base = $this->policy->initialBackoff
            * ($this->policy->backoffMultiplier ** $this->retries);
        $base = min($base, $this->policy->maxBackoff);
        ++$this->retries;

        $delay = $base * $this->randomizer->getFloat(0.8, 1.2);

        return $remainingSeconds === null ? $delay : min($delay, $remainingSeconds);
    }

    /**
     * Reset the exponential retry sequence.
     */
    public function reset(): void
    {
        $this->retries = 0;
    }

    /**
     * Resolve a present retry-pushback header and reset the sequence.
     */
    public function pushbackDelay(string|array $value): ?float
    {
        $milliseconds = self::parsePushback($value);

        if ($milliseconds === null) {
            return null;
        }

        $this->reset();

        return $milliseconds / 1000;
    }

    /**
     * Parse a present retry-pushback header in milliseconds.
     */
    private static function parsePushback(string|array $value): ?int
    {
        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0');

        if ($normalized === '') {
            return 0;
        }

        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $normalized;
    }
}
