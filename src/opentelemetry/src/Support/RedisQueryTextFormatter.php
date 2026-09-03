<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

class RedisQueryTextFormatter
{
    /**
     * Format a canonical Redis command without exposing value arguments.
     *
     * @param array<int, mixed> $parameters
     */
    public function format(string $command, array $parameters): string
    {
        $parameters = match ($command) {
            'DEL',
            'EXISTS',
            'MGET',
            'PFCOUNT',
            'PFMERGE',
            'SDIFF',
            'SDIFFSTORE',
            'SINTER',
            'SINTERSTORE',
            'SUNION',
            'SUNIONSTORE',
            'TOUCH',
            'UNLINK',
            'WATCH' => $parameters,

            'COPY',
            'LMOVE',
            'RENAME',
            'RENAMENX',
            'RPOPLPUSH',
            'SMOVE' => array_slice($parameters, 0, 2),

            'BITOP' => array_slice($parameters, 1),

            'HDEL' => $parameters,

            'HEXISTS',
            'HGET',
            'HINCRBY',
            'HINCRBYFLOAT',
            'HSETNX',
            'HSTRLEN' => array_slice($parameters, 0, 2),

            'HSET' => $this->hashKeyAndFields($parameters),

            'OBJECT',
            'XGROUP',
            'XINFO' => array_slice($parameters, 1, 1),

            'PUBLISH',
            'SPUBLISH' => array_slice($parameters, 0, 1),

            'PSUBSCRIBE',
            'PUNSUBSCRIBE',
            'SUBSCRIBE',
            'UNSUBSCRIBE' => $parameters,

            'APPEND',
            'BITCOUNT',
            'BITFIELD',
            'BITPOS',
            'DECR',
            'DECRBY',
            'DUMP',
            'EXPIRE',
            'EXPIREAT',
            'EXPIRETIME',
            'GEOADD',
            'GEODIST',
            'GEOHASH',
            'GEOPOS',
            'GEOSEARCH',
            'GET',
            'GETBIT',
            'GETDEL',
            'GETEX',
            'GETRANGE',
            'GETSET',
            'HGETALL',
            'HGETDEL',
            'HGETEX',
            'HEXPIRE',
            'HEXPIREAT',
            'HEXPIRETIME',
            'HKEYS',
            'HLEN',
            'HMGET',
            'HMSET',
            'HPEXPIRE',
            'HPEXPIREAT',
            'HPEXPIRETIME',
            'HPERSIST',
            'HPTTL',
            'HRANDFIELD',
            'HSCAN',
            'HSETEX',
            'HTTL',
            'HVALS',
            'INCR',
            'INCRBY',
            'INCRBYFLOAT',
            'LINDEX',
            'LINSERT',
            'LLEN',
            'LPOP',
            'LPOS',
            'LPUSH',
            'LPUSHX',
            'LRANGE',
            'LREM',
            'LSET',
            'LTRIM',
            'PERSIST',
            'PEXPIRE',
            'PEXPIREAT',
            'PEXPIRETIME',
            'PFADD',
            'PSETEX',
            'PTTL',
            'RESTORE',
            'RPOP',
            'RPUSH',
            'RPUSHX',
            'SADD',
            'SCARD',
            'SISMEMBER',
            'SMEMBERS',
            'SMISMEMBER',
            'SORT',
            'SORT_RO',
            'SPOP',
            'SRANDMEMBER',
            'SREM',
            'SSCAN',
            'SET',
            'SETBIT',
            'SETEX',
            'SETNX',
            'STRLEN',
            'TTL',
            'TYPE',
            'XACK',
            'XADD',
            'XAUTOCLAIM',
            'XCLAIM',
            'XDEL',
            'XLEN',
            'XPENDING',
            'XRANGE',
            'XREVRANGE',
            'XSETID',
            'XTRIM',
            'ZADD',
            'ZCARD',
            'ZCOUNT',
            'ZDIFFSTORE',
            'ZINCRBY',
            'ZINTERSTORE',
            'ZLEXCOUNT',
            'ZPOPMAX',
            'ZPOPMIN',
            'ZRANDMEMBER',
            'ZRANGE',
            'ZRANGEBYLEX',
            'ZRANGEBYSCORE',
            'ZRANK',
            'ZREM',
            'ZREMRANGEBYLEX',
            'ZREMRANGEBYRANK',
            'ZREMRANGEBYSCORE',
            'ZREVRANGE',
            'ZREVRANGEBYLEX',
            'ZREVRANGEBYSCORE',
            'ZREVRANK',
            'ZSCAN',
            'ZSCORE',
            'ZUNIONSTORE' => array_slice($parameters, 0, 1),

            'BLMOVE',
            'BRPOPLPUSH',
            'ZRANGESTORE' => array_slice($parameters, 0, 2),

            default => [],
        };

        $parts = [$command];

        foreach ($parameters as $parameter) {
            if (is_string($parameter) || is_int($parameter) || is_float($parameter) || is_bool($parameter)) {
                $parts[] = is_bool($parameter)
                    ? ($parameter ? '1' : '0')
                    : (string) $parameter;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Return a hash key followed by its scalar field positions.
     *
     * @param array<int, mixed> $parameters
     * @return array<int, mixed>
     */
    protected function hashKeyAndFields(array $parameters): array
    {
        $selected = array_slice($parameters, 0, 1);

        for ($index = 1, $count = count($parameters); $index < $count; $index += 2) {
            $selected[] = $parameters[$index];
        }

        return $selected;
    }
}
