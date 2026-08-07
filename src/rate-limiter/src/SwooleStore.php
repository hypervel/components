<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\RateLimiter\Concerns\CalculatesRateLimits;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\Exceptions\SwooleTableFullException;
use Hypervel\RateLimiter\Swoole\TableState;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class SwooleStore implements Store
{
    use CalculatesRateLimits;

    /**
     * Create a new Swoole rate limiter store.
     */
    public function __construct(
        protected TableState $state,
        protected float $memoryLimitBuffer,
        protected LoggerInterface $logger,
    ) {
        if ($memoryLimitBuffer <= 0 || $memoryLimitBuffer >= 1) {
            throw new InvalidArgumentException(
                'The Swoole rate limiter memory limit buffer must be greater than zero and less than one.'
            );
        }
    }

    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(string $key, AdmissionPolicy $policy): LimitResult
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            /** @var array{LimitResult, bool} $outcome */
            $outcome = $this->state->withLock($key, function () use ($key, $policy): array {
                [$value, $secondaryValue, $expiresAt] = $this->storedState($key);

                $result = $this->calculateConsume(
                    $policy,
                    $this->currentTimeInMicroseconds(),
                    $value,
                    $secondaryValue,
                    $expiresAt,
                );

                if ($result->denied()) {
                    return [$result, true];
                }

                return [
                    $result,
                    $this->writeState($key, $value, $secondaryValue, $expiresAt),
                ];
            });

            [$result, $stored] = $outcome;

            if ($stored) {
                return $result;
            }

            if ($attempt === 0) {
                $this->pruneExpiredRows();
            }
        }

        throw new SwooleTableFullException(
            "Swoole rate limiter table [{$this->state->name()}] cannot allocate a new entry after pruning expired state."
        );
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult
    {
        return $this->state->withLock($key, function () use ($key, $policy): LimitResult|BackoffResult {
            [$value, $secondaryValue, $expiresAt] = $this->storedState($key);

            return $this->calculateInspection(
                $policy,
                $this->currentTimeInMicroseconds(),
                $value,
                $secondaryValue,
                $expiresAt,
            );
        });
    }

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            /** @var array{BackoffResult, bool} $outcome */
            $outcome = $this->state->withLock($key, function () use ($key, $backoff): array {
                [$value, $secondaryValue, $expiresAt] = $this->storedState($key);

                $result = $this->calculateFailure(
                    $backoff,
                    $this->currentTimeInMicroseconds(),
                    $value,
                    $secondaryValue,
                    $expiresAt,
                );

                return [
                    $result,
                    $this->writeState($key, $value, $secondaryValue, $expiresAt),
                ];
            });

            [$result, $stored] = $outcome;

            if ($stored) {
                return $result;
            }

            if ($attempt === 0) {
                $this->pruneExpiredRows();
            }
        }

        throw new SwooleTableFullException(
            "Swoole rate limiter table [{$this->state->name()}] cannot allocate a new entry after pruning expired state."
        );
    }

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool
    {
        return $this->state->withLock(
            $key,
            fn (): bool => $this->state->table()->del($key),
        );
    }

    /**
     * Prune every expired entry.
     */
    public function pruneExpiredRows(): int
    {
        $now = $this->currentTimeInMicroseconds();
        $expiredKeys = [];
        $pruned = 0;

        foreach ($this->state->table() as $key => $row) {
            $expiresAt = $row['expires_at'] ?? null;

            if (! is_int($expiresAt) || $expiresAt <= 0 || $expiresAt > $now) {
                continue;
            }

            $expiredKeys[] = (string) $key;
        }

        // Deleting during Swoole's positional collision-chain iteration skips rows.
        foreach ($expiredKeys as $key) {
            $pruned += $this->state->withLock($key, function () use ($key, $now): int {
                $row = $this->state->table()->get($key);
                $expiresAt = $row === false ? null : ($row['expires_at'] ?? null);

                if (! is_int($expiresAt) || $expiresAt <= 0 || $expiresAt > $now) {
                    return 0;
                }

                return $this->state->table()->del($key) ? 1 : 0;
            });
        }

        return $pruned;
    }

    /**
     * Prune expired entries and report table pressure.
     */
    public function maintain(): int
    {
        $pruned = $this->pruneExpiredRows();
        $this->reportTablePressure();

        return $pruned;
    }

    /**
     * Get and validate numeric state for a physical limiter key.
     *
     * @return array{int, int, int}
     */
    protected function storedState(string $key): array
    {
        $row = $this->state->table()->get($key);

        if ($row === false) {
            return [0, 0, 0];
        }

        $value = $row['value'] ?? null;
        $secondaryValue = $row['secondary_value'] ?? null;
        $expiresAt = $row['expires_at'] ?? null;

        if (! is_int($value) || ! is_int($secondaryValue) || ! is_int($expiresAt)
            || $value < 0 || $secondaryValue < 0 || $expiresAt < 0
            || $value > AdmissionPolicy::MAX_INTEGER
            || $secondaryValue > AdmissionPolicy::MAX_INTEGER
            || $expiresAt > AdmissionPolicy::MAX_INTEGER) {
            throw new UnexpectedValueException('The stored Swoole rate limiter state is invalid.');
        }

        return [$value, $secondaryValue, $expiresAt];
    }

    /**
     * Write numeric state for a physical limiter key.
     */
    protected function writeState(string $key, int $value, int $secondaryValue, int $expiresAt): bool
    {
        return $this->state->table()->set($key, [
            'value' => $value,
            'secondary_value' => $secondaryValue,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Report exhausted table headroom after periodic pruning.
     */
    protected function reportTablePressure(): void
    {
        $table = $this->state->table();
        $stats = $table->stats();
        $conflictRate = 1 - ((int) $stats['available_slice_num'] / (int) $stats['total_slice_num']);
        $fillRate = (int) $stats['num'] / $table->getSize();
        $threshold = 1 - $this->memoryLimitBuffer;

        if ($conflictRate <= $threshold && $fillRate <= $threshold) {
            return;
        }

        $this->logger->warning(
            "Swoole rate limiter table [{$this->state->name()}] is nearing capacity.",
            [
                'conflict_rate' => $conflictRate,
                'fill_rate' => $fillRate,
                'threshold' => $threshold,
            ],
        );
    }
}
