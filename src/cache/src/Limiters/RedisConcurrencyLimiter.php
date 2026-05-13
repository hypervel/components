<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Redis\LuaScripts;
use Hypervel\Redis\RedisConnection;

/**
 * Redis-optimized concurrency limiter.
 */
class RedisConcurrencyLimiter extends ConcurrencyLimiter
{
    /**
     * Precomputed prefixed slot keys for Lua KEYS. Built once in the constructor.
     *
     * @var list<string>
     */
    protected array $prefixedSlots;

    /**
     * Create a new Redis-optimized concurrency limiter instance.
     */
    public function __construct(
        RedisStore $store,
        string $name,
        int $maxLocks,
        int $releaseAfter,
    ) {
        parent::__construct($store, $name, $maxLocks, $releaseAfter);

        $prefix = $store->getPrefix();
        $this->prefixedSlots = array_map(
            fn (string $slot): string => $prefix . $slot,
            $this->slots,
        );
    }

    /**
     * Atomically claim a free slot via a single Lua script.
     *
     * Two correctness invariants:
     *
     * 1. The Lua script writes the prefixed slot key to Redis and returns the
     *    UNPREFIXED slot name (e.g. "my-funnel1") so RedisStore::restoreLock()
     *    prepends the prefix exactly once when constructing the Lock object.
     *
     * 2. The owner ID must be pre-packed via $connection->pack() before being
     *    passed into Lua. phpredis does NOT auto-serialize eval() ARGV (regular
     *    commands like set() do). RedisLock::release() later packs $this->owner
     *    before its owner-check Lua, so the value Redis stores at acquire time
     *    must already be in packed form. If we passed raw $id here, Redis would
     *    store the raw string, but release would compare against a packed value
     *    — silent mismatch, slot leaks until TTL. We pass the RAW $id to
     *    restoreLock() so the returned RedisLock's owner field is raw; release
     *    will pack it consistently with what we stored.
     *
     * Using withConnection() also keeps both pack() and eval() on the same
     * checked-out pool connection, avoiding two pool roundtrips per attempt.
     */
    protected function acquire(string $id): bool|Lock
    {
        // Without slots there's nothing to claim. Calling eval with zero KEYS
        // would error inside Lua via unpack({}) → redis.call('mget') with no args.
        if ($this->prefixedSlots === []) {
            return false;
        }

        /** @var RedisStore $store */
        $store = $this->store;

        return $store->lockConnection()->withConnection(function (RedisConnection $connection) use ($id, $store): bool|Lock {
            $packedOwner = $connection->pack([$id])[0];

            $result = $connection->eval(...array_merge(
                [LuaScripts::acquireConcurrencySlot(), count($this->prefixedSlots)],
                $this->prefixedSlots,
                [$this->name, $this->releaseAfter, $packedOwner],
            ));

            return is_string($result) ? $store->restoreLock($result, $id) : false;
        });
    }
}
