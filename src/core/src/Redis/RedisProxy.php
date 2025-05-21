<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Closure;
use Hyperf\Redis\RedisProxy as HyperfRedisProxy;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class RedisProxy extends HyperfRedisProxy
{
    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void
    {
        $callback = fn ($redis, $channel, $message) => $callback($message, $channel);

        parent::subscribe(Arr::wrap($channels), $callback);
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void
    {
        $callback = fn ($redis, $pattern, $channel, $message) => $callback($message, $channel);

        parent::psubscribe(Arr::wrap($channels), $callback);
    }

    /**
     * Returns the value of the given key.
     */
    public function get(string $key): ?string
    {
        $result = parent::__call('get', [$key]);

        return $result !== false ? $result : null;
    }

    /**
     * Get the values of all the given keys.
     */
    public function mget(array $keys): array
    {
        return array_map(function ($value) {
            return $value !== false ? $value : null;
        }, parent::__call('mget', [$keys]));
    }

    /**
     * Set the string value in the argument as the value of the key.
     */
    public function set(string $key, mixed $value, ?string $expireResolution = null, ?int $expireTTL = null, ?string $flag = null): bool
    {
        return parent::__call('set', [
            $key,
            $value,
            $expireResolution ? [$flag, $expireResolution => $expireTTL] : null,
        ]);
    }

    /**
     * Set the given key if it doesn't exist.
     */
    public function setnx(string $key, string $value): int
    {
        return (int) parent::__call('setnx', [$key, $value]);
    }

    /**
     * Get the value of the given hash fields.
     */
    public function hmget(string $key, mixed ...$dictionary): array
    {
        if (count($dictionary) === 1) {
            $dictionary = $dictionary[0];
        }

        return array_values(
            parent::__call('hmget', [$key, $dictionary])
        );
    }

    /**
     * Set the given hash fields to their respective values.
     */
    public function hmset(string $key, mixed ...$dictionary): int
    {
        if (count($dictionary) === 1) {
            $dictionary = $dictionary[0];
        } else {
            $input = new Collection($dictionary);

            $dictionary = $input->nth(2)->combine($input->nth(2, 1))->toArray();
        }

        return parent::__call('hmset', [$key, $dictionary]);
    }

    /**
     * Set the given hash field if it doesn't exist.
     */
    public function hsetnx(string $hash, string $key, string $value): int
    {
        return (int) parent::__call('hsetnx', [$hash, $key, $value]);
    }

    /**
     * Removes the first count occurrences of the value element from the list.
     */
    public function lrem(string $key, int $count, mixed $value): false|int
    {
        return parent::__call('lrem', [$key, $value, $count]);
    }

    /**
     * Removes and returns the first element of the list stored at key.
     */
    public function blpop(mixed ...$arguments): ?array
    {
        $result = parent::__call('blpop', $arguments);

        return empty($result) ? null : $result;
    }

    /**
     * Removes and returns the last element of the list stored at key.
     */
    public function brpop(mixed ...$arguments): ?array
    {
        $result = parent::__call('brpop', $arguments);

        return empty($result) ? null : $result;
    }

    /**
     * Removes and returns a random element from the set value at key.
     *
     * @return false|mixed
     */
    public function spop(string $key, ?int $count = 1): mixed
    {
        return parent::__call('spop', [$key, $count]);
    }

    /**
     * Add one or more members to a sorted set or update its score if it already exists.
     */
    public function zadd(string $key, mixed ...$dictionary): int
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

        return parent::__call(
            'zadd',
            array_merge(
                [$key],
                [$options],
                array_values($dictionary)
            )
        );
    }

    /**
     * Return elements with score between $min and $max.
     */
    public function zrangebyscore(string $key, mixed $min, mixed $max, array $options = []): array
    {
        if (isset($options['limit']) && ! array_is_list($options['limit'])) {
            $options['limit'] = [
                $options['limit']['offset'],
                $options['limit']['count'],
            ];
        }

        return parent::__call('zRangeByScore', [$key, $min, $max, $options]);
    }

    /**
     * Return elements with score between $min and $max.
     */
    public function zrevrangebyscore(string $key, mixed $min, mixed $max, array $options = []): array
    {
        if (isset($options['limit']) && ! array_is_list($options['limit'])) {
            $options['limit'] = [
                $options['limit']['offset'],
                $options['limit']['count'],
            ];
        }

        return parent::__call('zRevRangeByScore', [$key, $min, $max, $options]);
    }

    /**
     * Find the intersection between sets and store in a new set.
     */
    public function zinterstore(string $output, array $keys, array $options = []): int
    {
        return parent::__call('zinterstore', [
            $output,
            $keys,
            $options['weights'] ?? null,
            $options['aggregate'] ?? 'sum',
        ]);
    }

    /**
     * Find the union between sets and store in a new set.
     */
    public function zunionstore(string $output, array $keys, array $options = []): int
    {
        return parent::__call('zunionstore', [
            $output,
            $keys,
            $options['weights'] ?? null,
            $options['aggregate'] ?? 'sum',
        ]);
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
        $options = $this->getScanOptions($arguments);

        $result = parent::scan(
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
        $options = $this->getScanOptions($arguments);

        $result = parent::zscan(
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
        $options = $this->getScanOptions($arguments);

        $result = parent::hscan(
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
        $options = $this->getScanOptions($arguments);

        $result = parent::sscan(
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
    public function evalsha(string $script, int $numkeys, mixed ...$arguments): mixed
    {
        return $this->__call('evalsha', [
            $this->script('load', $script),
            $arguments,
            $numkeys,
        ]);
    }

    /**
     * Evaluate a script and return its result.
     */
    public function eval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
    {
        return $this->__call('eval', [$script, $arguments, $numberOfKeys]);
    }

    /**
     * Flush the selected Redis database.
     */
    public function flushdb(): mixed
    {
        $arguments = func_get_args();

        if (strtoupper((string) ($arguments[0] ?? null)) === 'ASYNC') {
            return $this->__call('flushdb', [true]);
        }

        return $this->__call('flushdb', []);
    }

    /**
     * Execute a raw command.
     */
    public function executeRaw(array $parameters): mixed
    {
        return $this->__call('rawCommand', $parameters);
    }
}
