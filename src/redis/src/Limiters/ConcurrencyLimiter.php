<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\LuaScripts;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
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
     * The slot key prefix.
     */
    protected string $keyPrefix;

    /**
     * Create a new concurrency limiter instance.
     *
     * @param RedisProxy $redis the Redis connection instance
     * @param string $name the name of the limiter
     * @param int $maxLocks the allowed number of concurrent tasks
     * @param int $releaseAfter the number of seconds a slot should be maintained
     */
    public function __construct(
        protected RedisProxy $redis,
        string $name,
        protected int $maxLocks,
        protected int $releaseAfter
    ) {
        // All slot keys must hash to one cluster node: the acquire script
        // runs a multi-key MGET, which fails with CROSSSLOT otherwise.
        $this->keyPrefix = $redis->isCluster() && ! RedisConnection::hasHashTag($name)
            ? '{' . $name . '}'
            : $name;

        $this->slots = $maxLocks < 1
            ? []
            : array_map(fn (int $i): string => $this->keyPrefix . $i, range(1, $maxLocks));
    }

    /**
     * Acquire a lease on one of the limiter's slots, waiting up to the given timeout.
     *
     * @throws LimiterTimeoutException
     */
    public function acquire(int $timeout, int $sleep = 250): ConcurrencyLease
    {
        $id = Str::random(20);

        $starting = ((int) now()->format('Uu')) / 1000;

        $milliseconds = $timeout * 1000;

        while (! $slot = $this->claimSlot($id)) {
            $now = ((int) now()->format('Uu')) / 1000;

            if (($now + $sleep - $milliseconds) >= $starting) {
                throw new LimiterTimeoutException;
            }

            Sleep::usleep($sleep * 1000);
        }

        return new ConcurrencyLease($this->redis, $slot, $id, $this->releaseAfter);
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
            $callbackException = null;

            try {
                $result = $callback();
            } catch (Throwable $exception) {
                $callbackException = $exception;
            }

            try {
                $lease->release();
            } catch (Throwable $exception) {
                if ($callbackException === null) {
                    throw $exception;
                }
            }

            if ($callbackException !== null) {
                throw $callbackException;
            }

            return $result;
        }

        return true;
    }

    /**
     * Attempt to claim a free slot.
     *
     * @param string $id a unique identifier for this lease
     */
    protected function claimSlot(string $id): false|string
    {
        // Without slots there's nothing to claim. Calling eval with zero KEYS
        // would error inside Lua via unpack({}) → redis.call('mget') with no args.
        if ($this->slots === []) {
            return false;
        }

        $result = $this->redis->withConnection(
            fn (RedisConnection $connection): mixed => $connection->evalWithShaCache(
                LuaScripts::acquireConcurrencySlot(),
                $this->slots,
                [$this->keyPrefix, $this->releaseAfter, $id],
            ),
        );

        return is_string($result) ? $result : false;
    }
}
