<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\RateLimiter\Concerns\CalculatesRateLimits;
use Hypervel\RateLimiter\Contracts\Store;

/**
 * Hold rate limiter state local to one cooperative worker.
 *
 * Transitions must not introduce a suspension point because worker-local
 * atomicity depends on each read, calculation, and write running to completion.
 */
class WorkerArrayStore implements Store
{
    use CalculatesRateLimits;

    /**
     * The numeric rate limiter state held for this worker's lifetime.
     *
     * @var array<string, array{value: int, secondary_value: int, expires_at: int}>
     */
    protected array $states = [];

    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(string $key, AdmissionPolicy $policy): LimitResult
    {
        [$value, $secondaryValue, $expiresAt] = $this->state($key);

        $result = $this->calculateConsume(
            $policy,
            $this->currentTimeInMicroseconds(),
            $value,
            $secondaryValue,
            $expiresAt,
        );

        if ($result->allowed()) {
            $this->states[$key] = [
                'value' => $value,
                'secondary_value' => $secondaryValue,
                'expires_at' => $expiresAt,
            ];
        }

        return $result;
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult
    {
        [$value, $secondaryValue, $expiresAt] = $this->state($key);

        return $this->calculateInspection(
            $policy,
            $this->currentTimeInMicroseconds(),
            $value,
            $secondaryValue,
            $expiresAt,
        );
    }

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        [$value, $secondaryValue, $expiresAt] = $this->state($key);

        $result = $this->calculateFailure(
            $backoff,
            $this->currentTimeInMicroseconds(),
            $value,
            $secondaryValue,
            $expiresAt,
        );

        $this->states[$key] = [
            'value' => $value,
            'secondary_value' => $secondaryValue,
            'expires_at' => $expiresAt,
        ];

        return $result;
    }

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool
    {
        if (! array_key_exists($key, $this->states)) {
            return false;
        }

        unset($this->states[$key]);

        return true;
    }

    /**
     * Get the numeric state for a physical key.
     *
     * @return array{int, int, int}
     */
    protected function state(string $key): array
    {
        $state = $this->states[$key] ?? null;

        return $state === null
            ? [0, 0, 0]
            : [$state['value'], $state['secondary_value'], $state['expires_at']];
    }
}
