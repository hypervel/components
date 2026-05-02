<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Contracts\Redis\LimiterTimeoutException;
use Hypervel\Redis\LuaScripts;
use Hypervel\Redis\RedisProxy;
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
     * @param RedisProxy $redis the Redis connection instance
     * @param string $name the name of the limiter
     * @param int $maxLocks the allowed number of concurrent tasks
     * @param int $releaseAfter the number of seconds a slot should be maintained
     */
    public function __construct(
        protected RedisProxy $redis,
        protected string $name,
        protected int $maxLocks,
        protected int $releaseAfter
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
                return tap($callback(), function () use ($slot, $id): void {
                    $this->release($slot, $id);
                });
            } catch (Throwable $exception) {
                $this->release($slot, $id);

                throw $exception;
            }
        }

        return true;
    }

    /**
     * Attempt to acquire the lock.
     *
     * @param string $id a unique identifier for this lock
     */
    protected function acquire(string $id): mixed
    {
        // Without slots there's nothing to claim. Calling eval with zero KEYS
        // would error inside Lua via unpack({}) → redis.call('mget') with no args.
        if ($this->slots === []) {
            return false;
        }

        return $this->redis->eval(...array_merge(
            [LuaScripts::acquireConcurrencySlot(), count($this->slots)],
            $this->slots,
            [$this->name, $this->releaseAfter, $id],
        ));
    }

    /**
     * Release the lock.
     */
    protected function release(string $key, string $id): void
    {
        $this->redis->eval(LuaScripts::releaseLock(), 1, $key, $id);
    }
}
