<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Support\Str;

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
     * Attempt to acquire the lock.
     */
    public function get(?callable $callback = null): mixed
    {
        $result = $this->acquire();

        if ($result && is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
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
        $starting = $this->currentTime();

        while (! $this->acquire()) {
            usleep($this->sleepMilliseconds * 1000);

            if ($this->currentTime() - $seconds >= $starting) {
                throw new LockTimeoutException();
            }
        }

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    /**
     * Returns the current owner of the lock.
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Specify the number of milliseconds to sleep in between blocked lock acquisition attempts.
     */
    public function betweenBlockedAttemptsSleepFor(int $milliseconds): static
    {
        $this->sleepMilliseconds = $milliseconds;

        return $this;
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    abstract protected function getCurrentOwner(): string;

    /**
     * Determines whether this lock is allowed to release the lock in the driver.
     */
    protected function isOwnedByCurrentProcess(): bool
    {
        return $this->getCurrentOwner() === $this->owner;
    }
}
