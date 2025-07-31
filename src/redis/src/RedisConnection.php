<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\RedisConnection as HyperfRedisConnection;
use Hypervel\Support\Collection;
use Redis;
use Throwable;

/**
 * Redis connection class with Laravel-style method transformations.
 *
 * @method mixed get(string $key) Get the value of a key
 * @method bool set(string $key, mixed $value, mixed $expireResolution = null, mixed $expireTTL = null, mixed $flag = null) Set the value of a key
 * @method array mget(array $keys) Get the values of multiple keys
 * @method int setnx(string $key, string $value) Set key if not exists
 * @method array hmget(string $key, mixed ...$fields) Get hash field values
 * @method bool hmset(string $key, mixed ...$dictionary) Set hash field values
 * @method int hsetnx(string $hash, string $key, string $value) Set hash field if not exists
 * @method false|int lrem(string $key, int $count, mixed $value) Remove list elements
 * @method null|array blpop(mixed ...$arguments) Blocking left pop from list
 * @method null|array brpop(mixed ...$arguments) Blocking right pop from list
 * @method mixed spop(string $key, int $count = 1) Remove and return random set member
 * @method int zadd(string $key, mixed ...$dictionary) Add members to sorted set
 * @method array zrangebyscore(string $key, mixed $min, mixed $max, array $options = []) Get sorted set members by score range
 * @method array zrevrangebyscore(string $key, mixed $min, mixed $max, array $options = []) Get sorted set members by score range (reverse)
 * @method int zinterstore(string $output, array $keys, array $options = []) Intersect sorted sets
 * @method int zunionstore(string $output, array $keys, array $options = []) Union sorted sets
 * @method mixed eval(string $script, int $numberOfKeys, mixed ...$arguments) Evaluate Lua script
 * @method mixed evalsha(string $script, int $numkeys, mixed ...$arguments) Evaluate Lua script by SHA1
 * @method mixed flushdb(mixed ...$arguments) Flush database
 * @method mixed executeRaw(array $parameters) Execute raw Redis command
 */
class RedisConnection extends HyperfRedisConnection
{
    /**
     * Determine if the connection calls should be transformed to Laravel style.
     */
    protected bool $shouldTransform = false;

    public function __call($name, $arguments)
    {
        try {
            if (in_array($name, ['subscribe', 'psubscribe'])) {
                return $this->callSubscribe($name, $arguments);
            }

            if ($this->shouldTransform) {
                $method = 'call' . ucfirst($name);
                if (method_exists($this, $method)) {
                    return $this->{$method}(...$arguments);
                }
            }

            return $this->connection->{$name}(...$arguments);
        } catch (Throwable $exception) {
            $result = $this->retry($name, $arguments, $exception);
        }

        return $result;
    }

    public function release(): void
    {
        $this->shouldTransform = false;

        parent::release();
    }

    /**
     * Determine if the connection calls should be transformed to Laravel style.
     */
    public function shouldTransform(bool $shouldTransform = true): static
    {
        $this->shouldTransform = $shouldTransform;

        return $this;
    }

    /**
     * Get the current transformation state.
     */
    public function getShouldTransform(): bool
    {
        return $this->shouldTransform;
    }

    /**
     * Returns the value of the given key.
     */
    protected function callGet(string $key): ?string
    {
        $result = $this->connection->get($key);

        return $result !== false ? $result : null;
    }

    /**
     * Get the values of all the given keys.
     */
    protected function callMget(array $keys): array
    {
        return array_map(function ($value) {
            return $value !== false ? $value : null;
        }, $this->connection->mGet($keys));
    }

    /**
     * Set the string value in the argument as the value of the key.
     */
    protected function callSet(string $key, mixed $value, ?string $expireResolution = null, ?int $expireTTL = null, ?string $flag = null): bool
    {
        return $this->connection->set(
            $key,
            $value,
            $expireResolution ? [$flag, $expireResolution => $expireTTL] : null,
        );
    }

    /**
     * Set the given key if it doesn't exist.
     */
    protected function callSetnx(string $key, string $value): int
    {
        return (int) $this->connection->setNx($key, $value);
    }

    /**
     * Get the value of the given hash fields.
     */
    protected function callHmget(string $key, mixed ...$dictionary): array
    {
        if (count($dictionary) === 1) {
            $dictionary = $dictionary[0];
        }

        return array_values(
            $this->connection->hMGet($key, $dictionary)
        );
    }

    /**
     * Set the given hash fields to their respective values.
     */
    protected function callHmset(string $key, mixed ...$dictionary): bool
    {
        if (count($dictionary) === 1) {
            $dictionary = $dictionary[0];
        } else {
            $input = new Collection($dictionary);

            $dictionary = $input->nth(2)->combine($input->nth(2, 1))->toArray();
        }

        return $this->connection->hMSet($key, $dictionary);
    }

    /**
     * Set the given hash field if it doesn't exist.
     */
    protected function callHsetnx(string $hash, string $key, string $value): int
    {
        return (int) $this->connection->hSetNx($hash, $key, $value);
    }

    /**
     * Removes the first count occurrences of the value element from the list.
     */
    protected function callLrem(string $key, int $count, mixed $value): false|int
    {
        return $this->connection->lRem($key, $value, $count);
    }

    /**
     * Removes and returns the first element of the list stored at key.
     */
    protected function callBlpop(mixed ...$arguments): ?array
    {
        $result = $this->connection->blPop(...$arguments);

        return empty($result) ? null : $result;
    }

    /**
     * Removes and returns the last element of the list stored at key.
     */
    protected function callBrpop(mixed ...$arguments): ?array
    {
        $result = $this->connection->brPop(...$arguments);

        return empty($result) ? null : $result;
    }

    /**
     * Removes and returns a random element from the set value at key.
     *
     * @return false|mixed
     */
    protected function callSpop(string $key, ?int $count = 1): mixed
    {
        return $this->connection->sPop($key, $count);
    }

    /**
     * Add one or more members to a sorted set or update its score if it already exists.
     */
    protected function callZadd(string $key, mixed ...$dictionary): int
    {
        if (is_array(end($dictionary))) {
            foreach (array_pop($dictionary) as $member => $score) {
                $dictionary[] = $score;
                $dictionary[] = $member;
            }
        }

        $options = [];

        foreach (array_slice($dictionary, 0, 3) as $i => $value) {
            if (in_array($value, ['nx', 'xx', 'ch', 'incr', 'gt', 'lt', 'NX', 'XX', 'CH', 'INCR', 'GT', 'LT'], true)) {
                $options[] = $value;

                unset($dictionary[$i]);
            }
        }

        return $this->connection->zAdd(
            $key,
            $options,
            ...array_values($dictionary)
        );
    }

    /**
     * Return elements with score between $min and $max.
     */
    protected function callZrangebyscore(string $key, mixed $min, mixed $max, array $options = []): array
    {
        if (isset($options['limit']) && ! array_is_list($options['limit'])) {
            $options['limit'] = [
                $options['limit']['offset'],
                $options['limit']['count'],
            ];
        }

        return $this->connection->zRangeByScore($key, $min, $max, $options);
    }

    /**
     * Return elements with score between $min and $max.
     */
    protected function callZrevrangebyscore(string $key, mixed $min, mixed $max, array $options = []): array
    {
        if (isset($options['limit']) && ! array_is_list($options['limit'])) {
            $options['limit'] = [
                $options['limit']['offset'],
                $options['limit']['count'],
            ];
        }

        return $this->connection->zRevRangeByScore($key, $min, $max, $options);
    }

    /**
     * Find the intersection between sets and store in a new set.
     */
    protected function callZinterstore(string $output, array $keys, array $options = []): int
    {
        return $this->connection->zinterstore(
            $output,
            $keys,
            $options['weights'] ?? null,
            $options['aggregate'] ?? 'sum',
        );
    }

    /**
     * Find the union between sets and store in a new set.
     */
    protected function callZunionstore(string $output, array $keys, array $options = []): int
    {
        return $this->connection->zunionstore(
            $output,
            $keys,
            $options['weights'] ?? null,
            $options['aggregate'] ?? 'sum',
        );
    }

    protected function getScanOptions(array $arguments): array
    {
        return is_array($arguments[0] ?? [])
            ? $arguments[0]
            : [
                'match' => $arguments[0] ?? '*',
                'count' => $arguments[1] ?? 10,
            ];
    }

    /**
     * Scans all keys based on options.
     *
     * @param array $arguments
     * @param mixed $cursor
     */
    public function scan(&$cursor, ...$arguments): mixed
    {
        if (! $this->shouldTransform) {
            return parent::scan($cursor, ...$arguments);
        }

        $options = $this->getScanOptions($arguments);

        $result = $this->connection->scan(
            $cursor,
            $options['match'] ?? '*',
            $options['count'] ?? 10
        );

        if ($result === false) {
            $result = [];
        }

        return $cursor === 0 && empty($result) ? false : [$cursor, $result];
    }

    /**
     * Scans the given set for all values based on options.
     *
     * @param string $key
     * @param array $arguments
     * @param mixed $cursor
     */
    public function zscan($key, &$cursor, ...$arguments): mixed
    {
        if (! $this->shouldTransform) {
            return parent::zScan($key, $cursor, ...$arguments);
        }

        $options = $this->getScanOptions($arguments);

        $result = $this->connection->zscan(
            $key,
            $cursor,
            $options['match'] ?? '*',
            $options['count'] ?? 10
        );

        if ($result === false) {
            $result = [];
        }

        return $cursor === 0 && empty($result) ? false : [$cursor, $result];
    }

    /**
     * Scans the given hash for all values based on options.
     *
     * @param string $key
     * @param array $arguments
     * @param mixed $cursor
     */
    public function hscan($key, &$cursor, ...$arguments): mixed
    {
        if (! $this->shouldTransform) {
            return parent::hScan($key, $cursor, ...$arguments);
        }

        $options = $this->getScanOptions($arguments);

        $result = $this->connection->hscan(
            $key,
            $cursor,
            $options['match'] ?? '*',
            $options['count'] ?? 10
        );

        if ($result === false) {
            $result = [];
        }

        return $cursor === 0 && empty($result) ? false : [$cursor, $result];
    }

    /**
     * Scans the given set for all values based on options.
     *
     * @param string $key
     * @param array $arguments
     * @param mixed $cursor
     */
    public function sscan($key, &$cursor, ...$arguments): mixed
    {
        if (! $this->shouldTransform) {
            return parent::sScan($key, $cursor, ...$arguments);
        }

        $options = $this->getScanOptions($arguments);

        $result = $this->connection->sscan(
            $key,
            $cursor,
            $options['match'] ?? '*',
            $options['count'] ?? 10
        );

        if ($result === false) {
            $result = [];
        }

        return $cursor === 0 && empty($result) ? false : [$cursor, $result];
    }

    /**
     * Evaluate a LUA script serverside, from the SHA1 hash of the script instead of the script itself.
     */
    protected function callEvalsha(string $script, int $numkeys, mixed ...$arguments): mixed
    {
        return $this->connection->evalSha(
            $this->connection->script('load', $script),
            $arguments,
            $numkeys,
        );
    }

    /**
     * Flush the selected Redis database.
     */
    protected function callFlushdb(mixed ...$arguments): mixed
    {
        if (strtoupper((string) ($arguments[0] ?? null)) === 'ASYNC') {
            return $this->connection->flushdb(true);
        }

        return $this->connection->flushdb();
    }

    /**
     * Execute a raw command.
     */
    protected function callExecuteRaw(array $parameters): mixed
    {
        return $this->connection->rawCommand(...$parameters);
    }

    protected function callSubscribe(string $name, array $arguments): mixed
    {
        $timeout = $this->connection->getOption(Redis::OPT_READ_TIMEOUT);

        // Set the read timeout to -1 to avoid connection timeout.
        $this->connection->setOption(Redis::OPT_READ_TIMEOUT, -1);

        try {
            return $this->connection->{$name}(...$arguments);
        } finally {
            // Restore the read timeout to the original value before
            // returning to the connection pool.
            $this->connection->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        }
    }
}
