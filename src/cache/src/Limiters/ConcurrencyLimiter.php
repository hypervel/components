<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use Throwable;

class ConcurrencyLimiter
{
    /**
     * Precomputed slot names. Built once in the constructor.
     *
     * @var list<string>
     */
    protected array $slots;

    /**
     * Create a new concurrency limiter instance.
     *
     * @param LockProvider $store the cache store instance
     * @param string $name the name of the limiter
     * @param int $maxLocks the allowed number of concurrent locks
     * @param int $releaseAfter the number of seconds a slot should be maintained
     */
    public function __construct(
        protected LockProvider $store,
        protected string $name,
        protected int $maxLocks,
        protected int $releaseAfter,
    ) {
        $this->slots = $maxLocks < 1
            ? []
            : array_map(fn (int $i): string => $name . $i, range(1, $maxLocks));
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @throws LimiterTimeoutException
     * @throws Throwable
     */
    public function block(int $timeout, ?callable $callback = null, int $sleep = 250): mixed
    {
        $starting = time();

        $id = Str::random(20);

        while (! $slot = $this->acquire($id)) {
            if (time() - $timeout >= $starting) {
                throw new LimiterTimeoutException;
            }

            Sleep::usleep($sleep * 1000);
        }

        if (is_callable($callback)) {
            try {
                return tap($callback(), function () use ($slot): void {
                    $this->release($slot);
                });
            } catch (Throwable $exception) {
                $this->release($slot);

                throw $exception;
            }
        }

        return true;
    }

    /**
     * Attempt to acquire a slot lock.
     */
    protected function acquire(string $id): bool|Lock
    {
        foreach ($this->slots as $slotName) {
            $lock = $this->store->lock($slotName, $this->releaseAfter, $id);

            if ($lock->acquire()) {
                return $lock;
            }
        }

        return false;
    }

    /**
     * Release the slot lock.
     */
    protected function release(Lock $lock): void
    {
        $lock->release();
    }
}
