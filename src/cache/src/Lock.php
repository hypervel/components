<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

abstract class Lock implements LockContract
{
    use InteractsWithTime;

    /**
     * The name of the lock.
     */
    protected string $name;

    /**
     * The number of seconds the lock should be maintained.
     */
    protected int $seconds;

    /**
     * The scope identifier of this lock.
     */
    protected string $owner;

    /**
     * The number of milliseconds to wait before re-attempting to acquire a lock while blocking.
     */
    protected int $sleepMilliseconds = 250;

    /**
     * Create a new lock instance.
     */
    public function __construct(string $name, int $seconds, ?string $owner = null)
    {
        if (is_null($owner)) {
            $owner = Str::random();
        }

        $this->name = $name;
        $this->owner = $owner;
        $this->seconds = $seconds;
    }

    /**
     * Attempt to acquire the lock.
     */
    abstract public function acquire(): bool;

    /**
     * Release the lock.
     */
    abstract public function release(): bool;

    /**
     * Return the owner value written into the driver for this lock.
     */
    abstract protected function getCurrentOwner(): ?string;

    /**
     * Attempt to acquire the lock.
     */
    public function get(?callable $callback = null): mixed
    {
        $result = $this->acquire();

        if ($result && is_callable($callback)) {
            try {
                $result = $callback();
            } catch (Throwable $throwable) {
                $this->releaseAfterFailure($throwable);
            }

            $this->release();
        }

        return $result;
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @throws LockTimeoutException
     */
    public function block(int $seconds, ?callable $callback = null): mixed
    {
        $deadline = (int) CarbonImmutable::now()->format('Uu') + $seconds * 1_000_000;

        while (! $this->acquire()) {
            $remaining = $deadline - (int) CarbonImmutable::now()->format('Uu');

            if ($remaining <= 0) {
                throw new LockTimeoutException;
            }

            ConnectionResolver::releaseIdleConnections();
            // Allow a final attempt without sleeping beyond the remaining wait.
            Sleep::usleep(min($this->sleepMilliseconds * 1000, $remaining));
        }

        if (is_callable($callback)) {
            try {
                $result = $callback();
            } catch (Throwable $throwable) {
                $this->releaseAfterFailure($throwable);
            }

            $this->release();

            return $result;
        }

        return true;
    }

    /**
     * Release the lock while preserving the primary failure.
     */
    protected function releaseAfterFailure(Throwable $failure): never
    {
        try {
            $this->release();
        } catch (CanceledException $cleanupCancellation) {
            if (! $failure instanceof CanceledException) {
                throw $cleanupCancellation;
            }
        } catch (Throwable) {
            // Preserve the callback failure as primary.
        }

        throw $failure;
    }

    /**
     * Attempt to refresh the lock for the given number of seconds.
     *
     * @throws RuntimeException
     */
    public function refresh(?int $seconds = null): bool
    {
        throw new RuntimeException('This lock driver does not support refreshing locks.');
    }

    /**
     * Get the number of seconds until the lock expires.
     *
     * @throws RuntimeException
     */
    public function getRemainingLifetime(): ?float
    {
        throw new RuntimeException('This lock driver does not support lifetime inspection.');
    }

    /**
     * Return the current owner of the lock.
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Determine if the lock is currently held by any process.
     */
    public function isLocked(): bool
    {
        return $this->getCurrentOwner() !== null;
    }

    /**
     * Determine whether this lock is allowed to release the lock in the driver.
     */
    public function isOwnedByCurrentProcess(): bool
    {
        return $this->isOwnedBy($this->owner);
    }

    /**
     * Determine whether this lock is owned by the given identifier.
     */
    public function isOwnedBy(?string $owner): bool
    {
        return $this->getCurrentOwner() === $owner;
    }

    /**
     * Specify the number of milliseconds to sleep in between blocked lock acquisition attempts.
     */
    public function betweenBlockedAttemptsSleepFor(int $milliseconds): static
    {
        $this->sleepMilliseconds = $milliseconds;

        return $this;
    }
}
