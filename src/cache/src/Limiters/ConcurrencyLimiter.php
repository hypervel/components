<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Limiters\Lease;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use Throwable;

use function Hypervel\Support\now;

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
     * Acquire a lease on one of the limiter's slots, waiting up to the given timeout.
     *
     * @throws LimiterTimeoutException
     */
    public function acquire(int $timeout, int $sleep = 250): Lease
    {
        $id = Str::random(20);

        $starting = ((int) now()->format('Uu')) / 1000;

        $milliseconds = $timeout * 1000;

        while (! $lock = $this->claimSlot($id)) {
            $now = ((int) now()->format('Uu')) / 1000;

            if (($now + $sleep - $milliseconds) >= $starting) {
                throw new LimiterTimeoutException;
            }

            Sleep::usleep($sleep * 1000);
        }

        return $lock instanceof RefreshableLock
            ? new RefreshableConcurrencyLease($lock)
            : new ConcurrencyLease($lock);
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * When no callback is given, the slot is reserved fire-and-forget: it is
     * held until the releaseAfter TTL reclaims it. Use acquire() to obtain a
     * releasable lease instead.
     *
     * @throws LimiterTimeoutException
     * @throws Throwable
     */
    public function block(int $timeout, ?callable $callback = null, int $sleep = 250): mixed
    {
        $lease = $this->acquire($timeout, $sleep);

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $lease->release();
            }
        }

        return true;
    }

    /**
     * Attempt to acquire a slot lock.
     */
    protected function claimSlot(string $id): false|Lock
    {
        foreach ($this->slots as $slotName) {
            $lock = $this->store->lock($slotName, $this->releaseAfter, $id);

            if ($lock->acquire()) {
                return $lock;
            }
        }

        return false;
    }
}
