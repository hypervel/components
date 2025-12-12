<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Redis\RedisConnection as HyperfRedisConnection;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Redis;
use RedisCluster;
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
 * @method static string _digest(mixed $value)
 * @method static string _pack(mixed $value)
 * @method static mixed _unpack(string $value)
 * @method static mixed acl(string $subcmd, string ...$args)
 * @method static \Redis|int|false append(string $key, mixed $value)
 * @method static \Redis|bool auth(mixed $credentials)
 * @method static \Redis|bool bgSave()
 * @method static \Redis|bool bgrewriteaof()
 * @method static \Redis|array|false waitaof(int $numlocal, int $numreplicas, int $timeout)
 * @method static \Redis|int|false bitcount(string $key, int $start = 0, int $end = -1, bool $bybit = false)
 * @method static \Redis|int|false bitop(string $operation, string $deskey, string $srckey, string ...$other_keys)
 * @method static \Redis|int|false bitpos(string $key, bool $bit, int $start = 0, int $end = -1, bool $bybit = false)
 * @method static \Redis|array|false|null blPop(array|string $key_or_keys, string|int|float $timeout_or_key, mixed ...$extra_args)
 * @method static \Redis|array|false|null brPop(array|string $key_or_keys, string|int|float $timeout_or_key, mixed ...$extra_args)
 * @method static \Redis|string|false brpoplpush(string $src, string $dst, int|float $timeout)
 * @method static \Redis|array|false bzPopMax(array|string $key, string|int $timeout_or_key, mixed ...$extra_args)
 * @method static \Redis|array|false bzPopMin(array|string $key, string|int $timeout_or_key, mixed ...$extra_args)
 * @method static \Redis|array|false|null bzmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method static \Redis|array|false|null zmpop(array $keys, string $from, int $count = 1)
 * @method static \Redis|array|false|null blmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method static \Redis|array|false|null lmpop(array $keys, string $from, int $count = 1)
 * @method static bool clearLastError()
 * @method static mixed client(string $opt, mixed ...$args)
 * @method static mixed command(string|null $opt = null, mixed ...$args)
 * @method static mixed config(string $operation, array|string|null $key_or_settings = null, string|null $value = null)
 * @method static bool connect(string $host, int $port = 6379, float $timeout = 0, string|null $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, array|null $context = null)
 * @method static \Redis|bool copy(string $src, string $dst, array|null $options = null)
 * @method static \Redis|int|false dbSize()
 * @method static \Redis|string debug(string $key)
 * @method static \Redis|int|false decr(string $key, int $by = 1)
 * @method static \Redis|int|false decrBy(string $key, int $value)
 * @method static \Redis|int|false del(array|string $key, string ...$other_keys)
 * @method static \Redis|int|false delex(string $key, array|null $options = null)
 * @method static \Redis|int|false delifeq(string $key, mixed $value)
 * @method static \Redis|string|false digest(string $key)
 * @method static \Redis|bool discard()
 * @method static \Redis|string|false dump(string $key)
 * @method static \Redis|string|false echo(string $str)
 * @method static mixed eval_ro(string $script_sha, array $args = [], int $num_keys = 0)
 * @method static mixed evalsha_ro(string $sha1, array $args = [], int $num_keys = 0)
 * @method static \Redis|array|false exec()
 * @method static \Redis|int|bool exists(mixed $key, mixed ...$other_keys)
 * @method static \Redis|bool expire(string $key, int $timeout, string|null $mode = null)
 * @method static \Redis|bool expireAt(string $key, int $timestamp, string|null $mode = null)
 * @method static \Redis|bool failover(array|null $to = null, bool $abort = false, int $timeout = 0)
 * @method static \Redis|int|false expiretime(string $key)
 * @method static \Redis|int|false pexpiretime(string $key)
 * @method static mixed fcall(string $fn, array $keys = [], array $args = [])
 * @method static mixed fcall_ro(string $fn, array $keys = [], array $args = [])
 * @method static \Redis|bool flushAll(bool|null $sync = null)
 * @method static \Redis|bool flushDB(bool|null $sync = null)
 * @method static \Redis|array|string|bool function(string $operation, mixed ...$args)
 * @method static \Redis|int|false geoadd(string $key, float $lng, float $lat, string $member, mixed ...$other_triples_and_options)
 * @method static \Redis|float|false geodist(string $key, string $src, string $dst, string|null $unit = null)
 * @method static \Redis|array|false geohash(string $key, string $member, string ...$other_members)
 * @method static \Redis|array|false geopos(string $key, string $member, string ...$other_members)
 * @method static mixed georadius(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method static mixed georadius_ro(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method static mixed georadiusbymember(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method static mixed georadiusbymember_ro(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method static array geosearch(string $key, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method static \Redis|array|int|false geosearchstore(string $dst, string $src, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method static mixed getAuth()
 * @method static \Redis|int|false getBit(string $key, int $idx)
 * @method static \Redis|string|bool getEx(string $key, array $options = [])
 * @method static int getDBNum()
 * @method static \Redis|string|bool getDel(string $key)
 * @method static string getHost()
 * @method static string|null getLastError()
 * @method static int getMode()
 * @method static mixed getOption(int $option)
 * @method static string|null getPersistentID()
 * @method static int getPort()
 * @method static \Redis|string|false getRange(string $key, int $start, int $end)
 * @method static \Redis|array|string|int|false lcs(string $key1, string $key2, array|null $options = null)
 * @method static float getReadTimeout()
 * @method static \Redis|string|false getset(string $key, mixed $value)
 * @method static float|false getTimeout()
 * @method static array getTransferredBytes()
 * @method static void clearTransferredBytes()
 * @method static \Redis|array|false getWithMeta(string $key)
 * @method static \Redis|int|false hDel(string $key, string $field, string ...$other_fields)
 * @method static \Redis|array|false hexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method static \Redis|array|false hpexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method static \Redis|array|false hexpireat(string $key, int $time, array $fields, string|null $mode = null)
 * @method static \Redis|array|false hpexpireat(string $key, int $mstime, array $fields, string|null $mode = null)
 * @method static \Redis|array|false httl(string $key, array $fields)
 * @method static \Redis|array|false hpttl(string $key, array $fields)
 * @method static \Redis|array|false hexpiretime(string $key, array $fields)
 * @method static \Redis|array|false hpexpiretime(string $key, array $fields)
 * @method static \Redis|array|false hpersist(string $key, array $fields)
 * @method static \Redis|bool hExists(string $key, string $field)
 * @method static mixed hGet(string $key, string $member)
 * @method static \Redis|array|false hGetAll(string $key)
 * @method static mixed hGetWithMeta(string $key, string $member)
 * @method static \Redis|array|false hgetdel(string $key, array $fields)
 * @method static \Redis|array|false hgetex(string $key, array $fields, string|array|null $expiry = null)
 * @method static \Redis|int|false hIncrBy(string $key, string $field, int $value)
 * @method static \Redis|float|false hIncrByFloat(string $key, string $field, float $value)
 * @method static \Redis|array|false hKeys(string $key)
 * @method static \Redis|int|false hLen(string $key)
 * @method static \Redis|array|false hMget(string $key, array $fields)
 * @method static \Redis|bool hMset(string $key, array $fieldvals)
 * @method static \Redis|array|string|false hRandField(string $key, array|null $options = null)
 * @method static \Redis|int|false hSet(string $key, mixed ...$fields_and_vals)
 * @method static \Redis|bool hSetNx(string $key, string $field, mixed $value)
 * @method static \Redis|int|false hsetex(string $key, array $fields, array|null $expiry = null)
 * @method static \Redis|int|false hStrLen(string $key, string $field)
 * @method static \Redis|array|false hVals(string $key)
 * @method static \Redis|int|false incr(string $key, int $by = 1)
 * @method static \Redis|int|false incrBy(string $key, int $value)
 * @method static \Redis|float|false incrByFloat(string $key, float $value)
 * @method static \Redis|array|false info(string ...$sections)
 * @method static bool isConnected()
 * @method static void keys(string $pattern)
 * @method static void lInsert(string $key, string $pos, mixed $pivot, mixed $value)
 * @method static \Redis|int|false lLen(string $key)
 * @method static \Redis|string|false lMove(string $src, string $dst, string $wherefrom, string $whereto)
 * @method static \Redis|string|false blmove(string $src, string $dst, string $wherefrom, string $whereto, float $timeout)
 * @method static \Redis|array|string|bool lPop(string $key, int $count = 0)
 * @method static \Redis|array|int|bool|null lPos(string $key, mixed $value, array|null $options = null)
 * @method static \Redis|int|false lPush(string $key, mixed ...$elements)
 * @method static \Redis|int|false rPush(string $key, mixed ...$elements)
 * @method static \Redis|int|false lPushx(string $key, mixed $value)
 * @method static \Redis|int|false rPushx(string $key, mixed $value)
 * @method static \Redis|bool lSet(string $key, int $index, mixed $value)
 * @method static int lastSave()
 * @method static mixed lindex(string $key, int $index)
 * @method static \Redis|array|false lrange(string $key, int $start, int $end)
 * @method static \Redis|bool ltrim(string $key, int $start, int $end)
 * @method static \Redis|bool migrate(string $host, int $port, array|string $key, int $dstdb, int $timeout, bool $copy = false, bool $replace = false, mixed $credentials = null)
 * @method static \Redis|bool move(string $key, int $index)
 * @method static \Redis|bool mset(array $key_values)
 * @method static \Redis|int|false msetex(array $key_values, int|float|array|null $expiry = null)
 * @method static \Redis|bool msetnx(array $key_values)
 * @method static \Redis|bool multi(int $value = 1)
 * @method static \Redis|string|int|false object(string $subcommand, string $key)
 * @method static bool pconnect(string $host, int $port = 6379, float $timeout = 0, string|null $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, array|null $context = null)
 * @method static \Redis|bool persist(string $key)
 * @method static bool pexpire(string $key, int $timeout, string|null $mode = null)
 * @method static \Redis|bool pexpireAt(string $key, int $timestamp, string|null $mode = null)
 * @method static \Redis|int pfadd(string $key, array $elements)
 * @method static \Redis|int|false pfcount(array|string $key_or_keys)
 * @method static \Redis|bool pfmerge(string $dst, array $srckeys)
 * @method static \Redis|string|bool ping(string|null $message = null)
 * @method static \Redis|bool psetex(string $key, int $expire, mixed $value)
 * @method static void psubscribe(array|string $channels, \Closure $callback)
 * @method static \Redis|int|false pttl(string $key)
 * @method static \Redis|int|false publish(string $channel, string $message)
 * @method static mixed pubsub(string $command, mixed $arg = null)
 * @method static \Redis|array|bool punsubscribe(array $patterns)
 * @method static \Redis|array|string|bool rPop(string $key, int $count = 0)
 * @method static \Redis|string|false randomKey()
 * @method static mixed rawcommand(string $command, mixed ...$args)
 * @method static \Redis|bool rename(string $old_name, string $new_name)
 * @method static \Redis|bool renameNx(string $key_src, string $key_dst)
 * @method static \Redis|bool reset()
 * @method static \Redis|bool restore(string $key, int $ttl, string $value, array|null $options = null)
 * @method static mixed role()
 * @method static \Redis|string|false rpoplpush(string $srckey, string $dstkey)
 * @method static \Redis|int|false sAdd(string $key, mixed $value, mixed ...$other_values)
 * @method static int sAddArray(string $key, array $values)
 * @method static \Redis|array|false sDiff(string $key, string ...$other_keys)
 * @method static \Redis|int|false sDiffStore(string $dst, string $key, string ...$other_keys)
 * @method static \Redis|array|false sInter(array|string $key, string ...$other_keys)
 * @method static \Redis|int|false sintercard(array $keys, int $limit = -1)
 * @method static \Redis|int|false sInterStore(array|string $key, string ...$other_keys)
 * @method static \Redis|array|false sMembers(string $key)
 * @method static \Redis|array|false sMisMember(string $key, string $member, string ...$other_members)
 * @method static \Redis|bool sMove(string $src, string $dst, mixed $value)
 * @method static \Redis|array|string|false sPop(string $key, int $count = 0)
 * @method static mixed sRandMember(string $key, int $count = 0)
 * @method static \Redis|array|false sUnion(string $key, string ...$other_keys)
 * @method static \Redis|int|false sUnionStore(string $dst, string $key, string ...$other_keys)
 * @method static \Redis|bool save()
 * @method static \Redis|int|false scard(string $key)
 * @method static mixed script(string $command, mixed ...$args)
 * @method static \Redis|bool select(int $db)
 * @method static string|false serverName()
 * @method static string|false serverVersion()
 * @method static \Redis|int|false setBit(string $key, int $idx, bool $value)
 * @method static \Redis|int|false setRange(string $key, int $index, string $value)
 * @method static bool setOption(int $option, mixed $value)
 * @method static void setex(string $key, int $expire, mixed $value)
 * @method static \Redis|bool sismember(string $key, mixed $value)
 * @method static \Redis|bool replicaof(string|null $host = null, int $port = 6379)
 * @method static \Redis|int|false touch(array|string $key_or_array, string ...$more_keys)
 * @method static mixed slowlog(string $operation, int $length = 0)
 * @method static mixed sort(string $key, array|null $options = null)
 * @method static mixed sort_ro(string $key, array|null $options = null)
 * @method static \Redis|int|false srem(string $key, mixed $value, mixed ...$other_values)
 * @method static bool ssubscribe(array $channels, callable $cb)
 * @method static \Redis|int|false strlen(string $key)
 * @method static void subscribe(array|string $channels, \Closure $callback)
 * @method static \Redis|array|bool sunsubscribe(array $channels)
 * @method static \Redis|bool swapdb(int $src, int $dst)
 * @method static \Redis|array time()
 * @method static \Redis|int|false ttl(string $key)
 * @method static \Redis|int|false type(string $key)
 * @method static \Redis|int|false unlink(array|string $key, string ...$other_keys)
 * @method static \Redis|array|bool unsubscribe(array $channels)
 * @method static \Redis|bool unwatch()
 * @method static \Redis|int|false vadd(string $key, array $values, mixed $element, array|null $options = null)
 * @method static \Redis|int|false vcard(string $key)
 * @method static \Redis|int|false vdim(string $key)
 * @method static \Redis|array|false vemb(string $key, mixed $member, bool $raw = false)
 * @method static \Redis|array|string|false vgetattr(string $key, mixed $member, bool $decode = true)
 * @method static \Redis|array|false vinfo(string $key)
 * @method static \Redis|bool vismember(string $key, mixed $member)
 * @method static \Redis|array|false vlinks(string $key, mixed $member, bool $withscores = false)
 * @method static \Redis|array|string|false vrandmember(string $key, int $count = 0)
 * @method static \Redis|array|false vrange(string $key, string $min, string $max, int $count = -1)
 * @method static \Redis|int|false vrem(string $key, mixed $member)
 * @method static \Redis|int|false vsetattr(string $key, mixed $member, array|string $attributes)
 * @method static \Redis|array|false vsim(string $key, mixed $member, array|null $options = null)
 * @method static \Redis|bool watch(array|string $key, string ...$other_keys)
 * @method static int|false wait(int $numreplicas, int $timeout)
 * @method static int|false xack(string $key, string $group, array $ids)
 * @method static \Redis|string|false xadd(string $key, string $id, array $values, int $maxlen = 0, bool $approx = false, bool $nomkstream = false)
 * @method static \Redis|array|bool xautoclaim(string $key, string $group, string $consumer, int $min_idle, string $start, int $count = -1, bool $justid = false)
 * @method static \Redis|array|bool xclaim(string $key, string $group, string $consumer, int $min_idle, array $ids, array $options)
 * @method static \Redis|int|false xdel(string $key, array $ids)
 * @method static \Redis|array|false xdelex(string $key, array $ids, string|null $mode = null)
 * @method static mixed xgroup(string $operation, string|null $key = null, string|null $group = null, string|null $id_or_consumer = null, bool $mkstream = false, int $entries_read = -2)
 * @method static mixed xinfo(string $operation, string|null $arg1 = null, string|null $arg2 = null, int $count = -1)
 * @method static \Redis|int|false xlen(string $key)
 * @method static \Redis|array|false xpending(string $key, string $group, string|null $start = null, string|null $end = null, int $count = -1, string|null $consumer = null)
 * @method static \Redis|array|bool xrange(string $key, string $start, string $end, int $count = -1)
 * @method static \Redis|array|bool xread(array $streams, int $count = -1, int $block = -1)
 * @method static \Redis|array|bool xreadgroup(string $group, string $consumer, array $streams, int $count = 1, int $block = 1)
 * @method static \Redis|array|bool xrevrange(string $key, string $end, string $start, int $count = -1)
 * @method static \Redis|int|false xtrim(string $key, string $threshold, bool $approx = false, bool $minid = false, int $limit = -1)
 * @method static \Redis|int|float|false zAdd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems)
 * @method static \Redis|int|false zCard(string $key)
 * @method static \Redis|int|false zCount(string $key, string|int $start, string|int $end)
 * @method static \Redis|float|false zIncrBy(string $key, float $value, mixed $member)
 * @method static \Redis|int|false zLexCount(string $key, string $min, string $max)
 * @method static \Redis|array|false zMscore(string $key, mixed $member, mixed ...$other_members)
 * @method static \Redis|array|false zPopMax(string $key, int|null $count = null)
 * @method static \Redis|array|false zPopMin(string $key, int|null $count = null)
 * @method static \Redis|array|false zRange(string $key, string|int $start, string|int $end, array|bool|null $options = null)
 * @method static \Redis|array|false zRangeByLex(string $key, string $min, string $max, int $offset = -1, int $count = -1)
 * @method static \Redis|array|false zRangeByScore(string $key, string $start, string $end, array $options = [])
 * @method static \Redis|int|false zrangestore(string $dstkey, string $srckey, string $start, string $end, array|bool|null $options = null)
 * @method static \Redis|array|string zRandMember(string $key, array|null $options = null)
 * @method static \Redis|int|false zRank(string $key, mixed $member)
 * @method static \Redis|int|false zRem(mixed $key, mixed $member, mixed ...$other_members)
 * @method static \Redis|int|false zRemRangeByLex(string $key, string $min, string $max)
 * @method static \Redis|int|false zRemRangeByRank(string $key, int $start, int $end)
 * @method static \Redis|int|false zRemRangeByScore(string $key, string $start, string $end)
 * @method static \Redis|array|false zRevRange(string $key, int $start, int $end, mixed $scores = null)
 * @method static \Redis|array|false zRevRangeByLex(string $key, string $max, string $min, int $offset = -1, int $count = -1)
 * @method static \Redis|array|false zRevRangeByScore(string $key, string $max, string $min, array|bool $options = [])
 * @method static \Redis|int|false zRevRank(string $key, mixed $member)
 * @method static \Redis|float|false zScore(string $key, mixed $member)
 * @method static \Redis|array|false zdiff(array $keys, array|null $options = null)
 * @method static \Redis|int|false zdiffstore(string $dst, array $keys)
 * @method static \Redis|array|false zinter(array $keys, array|null $weights = null, array|null $options = null)
 * @method static \Redis|int|false zintercard(array $keys, int $limit = -1)
 * @method static \Redis|array|false zunion(array $keys, array|null $weights = null, array|null $options = null)
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
     * Evaluate a script and return its result.
     */
    protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
    {
        return $this->connection->eval($script, $arguments, $numberOfKeys);
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
            return $this->connection->{$name}(
                ...$this->getSubscribeArguments($name, $arguments)
            );
        } finally {
            // Restore the read timeout to the original value before
            // returning to the connection pool.
            $this->connection->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        }
    }

    protected function getSubscribeArguments(string $name, array $arguments): array
    {
        $channels = Arr::wrap($arguments[0]);
        $callback = $arguments[1];

        if ($name === 'subscribe') {
            return [
                $channels,
                fn ($redis, $channel, $message) => $callback($message, $channel),
            ];
        }

        return [
            $channels,
            $callback = fn ($redis, $pattern, $channel, $message) => $callback($message, $channel),
        ];
    }

    /**
     * Get the underlying Redis client instance.
     *
     * Use this for operations requiring direct client access,
     * such as evalSha with pre-computed SHA hashes.
     */
    public function client(): Redis|RedisCluster
    {
        return $this->connection;
    }

    /**
     * Determine if a custom serializer is configured on the connection.
     */
    public function serialized(): bool
    {
        return defined('Redis::OPT_SERIALIZER')
            && $this->connection->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE;
    }

    /**
     * Determine if compression is configured on the connection.
     */
    public function compressed(): bool
    {
        return defined('Redis::OPT_COMPRESSION')
            && $this->connection->getOption(Redis::OPT_COMPRESSION) !== Redis::COMPRESSION_NONE;
    }

    /**
     * Pack values for use in Lua script ARGV parameters.
     *
     * Unlike regular Redis commands where phpredis auto-serializes,
     * Lua ARGV parameters must be pre-serialized strings.
     *
     * Requires phpredis 6.0+ which provides the _pack() method.
     *
     * @param array<int|string, mixed> $values
     * @return array<int|string, string>
     */
    public function pack(array $values): array
    {
        if (empty($values)) {
            return $values;
        }

        return array_map($this->connection->_pack(...), $values);
    }
}
