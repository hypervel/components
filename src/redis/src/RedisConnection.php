<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Generator;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Connection as BaseConnection;
use Hypervel\Pool\Exception\ConnectionException;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\Exceptions\InvalidRedisOptionException;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\Operations\FlushByPattern;
use Hypervel\Redis\Operations\SafeScan;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Psr\Log\LogLevel;
use Redis;
use RedisCluster;
use RedisException;
use Throwable;

/**
 * Redis connection class with Laravel-style method transformations.
 *
 * @method mixed get(string $key) Get the value of a key
 * @method bool set(string $key, mixed $value, mixed $expireResolution = null, mixed $expireTTL = null, mixed $flag = null) Set the value of a key
 * @method array mget(array $keys) Get the values of multiple keys
 * @method int setnx(string $key, string $value) Set key if not exists
 * @method int setNx(string $key, string $value) Set key if not exists
 * @method array hmget(string $key, mixed ...$fields) Get hash field values
 * @method bool hmset(string $key, mixed ...$dictionary) Set hash field values
 * @method int hsetnx(string $hash, string $key, string $value) Set hash field if not exists
 * @method mixed hget(string $key, string $member) Get hash field value
 * @method false|int hset(string $key, mixed ...$fields_and_vals) Set hash field values
 * @method false|int lrem(string $key, int $count, mixed $value) Remove list elements
 * @method false|int llen(string $key) Get list length
 * @method null|array blpop(mixed ...$arguments) Blocking left pop from list
 * @method null|array brpop(mixed ...$arguments) Blocking right pop from list
 * @method mixed spop(string $key, int $count = 1) Remove and return random set member
 * @method false|int sRem(string $key, mixed $value, mixed ...$other_values) Remove members from set
 * @method int zadd(string $key, mixed ...$dictionary) Add members to sorted set
 * @method false|int zcard(string $key) Get sorted set cardinality
 * @method false|int zcount(string $key, int|string $start, int|string $end) Count sorted set members by score range
 * @method array zrangebyscore(string $key, mixed $min, mixed $max, array $options = []) Get sorted set members by score range
 * @method array zrevrangebyscore(string $key, mixed $min, mixed $max, array $options = []) Get sorted set members by score range (reverse)
 * @method int zinterstore(string $output, array $keys, array $options = []) Intersect sorted sets
 * @method int zunionstore(string $output, array $keys, array $options = []) Union sorted sets
 * @method mixed eval(string $script, int $numberOfKeys, mixed ...$arguments) Evaluate Lua script
 * @method mixed evalsha(string $script, int $numkeys, mixed ...$arguments) Evaluate Lua script by SHA1
 * @method mixed flushdb(mixed ...$arguments) Flush database
 * @method mixed executeRaw(array $parameters) Execute raw Redis command
 * @method mixed pipeline(callable|null $callback = null) Execute commands in a pipeline
 * @method array smembers(string $key) Get all set members
 * @method false|int hdel(string $key, string ...$fields) Delete hash fields
 * @method false|int zrem(string $key, string ...$members) Remove sorted set members
 * @method false|int hlen(string $key) Get number of hash fields
 * @method array hkeys(string $key) Get all hash field names
 * @method string _serialize(mixed $value) Serialize a value using configured serializer
 * @method string _digest(mixed $value)
 * @method string _pack(mixed $value)
 * @method mixed _unpack(string $value)
 * @method mixed acl(string $subcmd, string ...$args)
 * @method false|int|Redis append(string $key, mixed $value)
 * @method bool|Redis auth(mixed $credentials)
 * @method bool|Redis bgSave()
 * @method bool|Redis bgrewriteaof()
 * @method array|false|Redis waitaof(int $numlocal, int $numreplicas, int $timeout)
 * @method false|int|Redis bitcount(string $key, int $start = 0, int $end = -1, bool $bybit = false)
 * @method false|int|Redis bitop(string $operation, string $deskey, string $srckey, string ...$other_keys)
 * @method false|int|Redis bitpos(string $key, bool $bit, int $start = 0, int $end = -1, bool $bybit = false)
 * @method null|array|false|Redis blPop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args)
 * @method null|array|false|Redis brPop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args)
 * @method false|Redis|string brpoplpush(string $src, string $dst, float|int $timeout)
 * @method array|false|Redis bzPopMax(array|string $key, int|string $timeout_or_key, mixed ...$extra_args)
 * @method array|false|Redis bzPopMin(array|string $key, int|string $timeout_or_key, mixed ...$extra_args)
 * @method null|array|false|Redis bzmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method null|array|false|Redis zmpop(array $keys, string $from, int $count = 1)
 * @method null|array|false|Redis blmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method null|array|false|Redis lmpop(array $keys, string $from, int $count = 1)
 * @method bool clearLastError()
 * @method mixed client(string $opt = '', mixed ...$args)
 * @method mixed command(string|null $opt = null, mixed ...$args)
 * @method mixed config(string $operation, array|string|null $key_or_settings = null, string|null $value = null)
 * @method bool connect(string $host, int $port = 6379, float $timeout = 0, string|null $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, array|null $context = null)
 * @method bool|Redis copy(string $src, string $dst, array|null $options = null)
 * @method false|int|Redis dbSize()
 * @method Redis|string debug(string $key)
 * @method false|int|Redis decr(string $key, int $by = 1)
 * @method false|int|Redis decrBy(string $key, int $value)
 * @method false|int|Redis del(array|string $key, string ...$other_keys)
 * @method false|int|Redis delex(string $key, array|null $options = null)
 * @method false|int|Redis delifeq(string $key, mixed $value)
 * @method false|Redis|string digest(string $key)
 * @method bool|Redis discard()
 * @method false|Redis|string dump(string $key)
 * @method false|Redis|string echo(string $str)
 * @method mixed eval_ro(string $script_sha, array $args = [], int $num_keys = 0)
 * @method mixed evalsha_ro(string $sha1, array $args = [], int $num_keys = 0)
 * @method array|false|Redis exec()
 * @method bool|int|Redis exists(mixed $key, mixed ...$other_keys)
 * @method bool|Redis expire(string $key, int $timeout, string|null $mode = null)
 * @method bool|Redis expireAt(string $key, int $timestamp, string|null $mode = null)
 * @method bool|Redis failover(array|null $to = null, bool $abort = false, int $timeout = 0)
 * @method false|int|Redis expiretime(string $key)
 * @method false|int|Redis pexpiretime(string $key)
 * @method mixed fcall(string $fn, array $keys = [], array $args = [])
 * @method mixed fcall_ro(string $fn, array $keys = [], array $args = [])
 * @method bool|Redis flushAll(bool|null $sync = null)
 * @method bool|Redis flushDB(bool|null $sync = null)
 * @method array|bool|Redis|string function(string $operation, mixed ...$args)
 * @method false|int|Redis geoadd(string $key, float $lng, float $lat, string $member, mixed ...$other_triples_and_options)
 * @method false|float|Redis geodist(string $key, string $src, string $dst, string|null $unit = null)
 * @method array|false|Redis geohash(string $key, string $member, string ...$other_members)
 * @method array|false|Redis geopos(string $key, string $member, string ...$other_members)
 * @method mixed georadius(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method mixed georadius_ro(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method mixed georadiusbymember(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method mixed georadiusbymember_ro(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method array geosearch(string $key, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method array|false|int|Redis geosearchstore(string $dst, string $src, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method mixed getAuth()
 * @method false|int|Redis getBit(string $key, int $idx)
 * @method bool|Redis|string getEx(string $key, array $options = [])
 * @method int getDBNum()
 * @method bool|Redis|string getDel(string $key)
 * @method string getHost()
 * @method null|string getLastError()
 * @method int getMode()
 * @method mixed getOption(int $option)
 * @method null|string getPersistentID()
 * @method int getPort()
 * @method false|Redis|string getRange(string $key, int $start, int $end)
 * @method array|false|int|Redis|string lcs(string $key1, string $key2, array|null $options = null)
 * @method float getReadTimeout()
 * @method false|Redis|string getset(string $key, mixed $value)
 * @method false|float getTimeout()
 * @method array getTransferredBytes()
 * @method void clearTransferredBytes()
 * @method array|false|Redis getWithMeta(string $key)
 * @method false|int|Redis hDel(string $key, string $field, string ...$other_fields)
 * @method array|false|Redis hexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method array|false|Redis hpexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method array|false|Redis hexpireat(string $key, int $time, array $fields, string|null $mode = null)
 * @method array|false|Redis hpexpireat(string $key, int $mstime, array $fields, string|null $mode = null)
 * @method array|false|Redis httl(string $key, array $fields)
 * @method array|false|Redis hpttl(string $key, array $fields)
 * @method array|false|Redis hexpiretime(string $key, array $fields)
 * @method array|false|Redis hpexpiretime(string $key, array $fields)
 * @method array|false|Redis hpersist(string $key, array $fields)
 * @method bool|Redis hExists(string $key, string $field)
 * @method mixed hGet(string $key, string $member)
 * @method array|false|Redis hGetAll(string $key)
 * @method mixed hGetWithMeta(string $key, string $member)
 * @method array|false|Redis hgetdel(string $key, array $fields)
 * @method array|false|Redis hgetex(string $key, array $fields, string|array|null $expiry = null)
 * @method false|int|Redis hIncrBy(string $key, string $field, int $value)
 * @method false|float|Redis hIncrByFloat(string $key, string $field, float $value)
 * @method array|false|Redis hKeys(string $key)
 * @method false|int|Redis hLen(string $key)
 * @method array|false|Redis hMget(string $key, array $fields)
 * @method bool|Redis hMset(string $key, array $fieldvals)
 * @method array|false|Redis|string hRandField(string $key, array|null $options = null)
 * @method false|int|Redis hSet(string $key, mixed ...$fields_and_vals)
 * @method bool|Redis hSetNx(string $key, string $field, mixed $value)
 * @method false|int|Redis hsetex(string $key, array $fields, array|null $expiry = null)
 * @method false|int|Redis hStrLen(string $key, string $field)
 * @method array|false|Redis hVals(string $key)
 * @method false|int|Redis incr(string $key, int $by = 1)
 * @method false|int|Redis incrBy(string $key, int $value)
 * @method false|float|Redis incrByFloat(string $key, float $value)
 * @method array|false|Redis info(string ...$sections)
 * @method bool isConnected()
 * @method void keys(string $pattern)
 * @method void lInsert(string $key, string $pos, mixed $pivot, mixed $value)
 * @method false|int|Redis lLen(string $key)
 * @method false|Redis|string lMove(string $src, string $dst, string $wherefrom, string $whereto)
 * @method false|Redis|string blmove(string $src, string $dst, string $wherefrom, string $whereto, float $timeout)
 * @method array|bool|Redis|string lPop(string $key, int $count = 0)
 * @method null|array|bool|int|Redis lPos(string $key, mixed $value, array|null $options = null)
 * @method false|int|Redis lPush(string $key, mixed ...$elements)
 * @method false|int|Redis rPush(string $key, mixed ...$elements)
 * @method false|int|Redis lPushx(string $key, mixed $value)
 * @method false|int|Redis rPushx(string $key, mixed $value)
 * @method bool|Redis lSet(string $key, int $index, mixed $value)
 * @method int lastSave()
 * @method mixed lindex(string $key, int $index)
 * @method array|false|Redis lrange(string $key, int $start, int $end)
 * @method bool|Redis ltrim(string $key, int $start, int $end)
 * @method bool|Redis migrate(string $host, int $port, array|string $key, int $dstdb, int $timeout, bool $copy = false, bool $replace = false, mixed $credentials = null)
 * @method bool|Redis move(string $key, int $index)
 * @method bool|Redis mset(array $key_values)
 * @method false|int|Redis msetex(array $key_values, int|float|array|null $expiry = null)
 * @method bool|Redis msetnx(array $key_values)
 * @method bool|Redis multi(int $value = 1)
 * @method false|int|Redis|string object(string $subcommand, string $key)
 * @method bool pconnect(string $host, int $port = 6379, float $timeout = 0, string|null $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, array|null $context = null)
 * @method bool|Redis persist(string $key)
 * @method bool pexpire(string $key, int $timeout, string|null $mode = null)
 * @method bool|Redis pexpireAt(string $key, int $timestamp, string|null $mode = null)
 * @method int|Redis pfadd(string $key, array $elements)
 * @method false|int|Redis pfcount(array|string $key_or_keys)
 * @method bool|Redis pfmerge(string $dst, array $srckeys)
 * @method bool|Redis|string ping(string|null $message = null)
 * @method bool|Redis psetex(string $key, int $expire, mixed $value)
 * @method void psubscribe(array|string $channels, \Closure $callback)
 * @method false|int|Redis pttl(string $key)
 * @method false|int|Redis publish(string $channel, string $message)
 * @method mixed pubsub(string $command, mixed $arg = null)
 * @method array|bool|Redis punsubscribe(array $patterns)
 * @method array|bool|Redis|string rPop(string $key, int $count = 0)
 * @method false|Redis|string randomKey()
 * @method mixed rawcommand(string $command, mixed ...$args)
 * @method bool|Redis rename(string $old_name, string $new_name)
 * @method bool|Redis renameNx(string $key_src, string $key_dst)
 * @method bool|Redis reset()
 * @method bool|Redis restore(string $key, int $ttl, string $value, array|null $options = null)
 * @method mixed role()
 * @method false|Redis|string rpoplpush(string $srckey, string $dstkey)
 * @method false|int|Redis sAdd(string $key, mixed $value, mixed ...$other_values)
 * @method int sAddArray(string $key, array $values)
 * @method array|false|Redis sDiff(string $key, string ...$other_keys)
 * @method false|int|Redis sDiffStore(string $dst, string $key, string ...$other_keys)
 * @method array|false|Redis sInter(array|string $key, string ...$other_keys)
 * @method false|int|Redis sintercard(array $keys, int $limit = -1)
 * @method false|int|Redis sInterStore(array|string $key, string ...$other_keys)
 * @method array|false|Redis sMembers(string $key)
 * @method array|false|Redis sMisMember(string $key, string $member, string ...$other_members)
 * @method bool|Redis sMove(string $src, string $dst, mixed $value)
 * @method array|false|Redis|string sPop(string $key, int $count = 0)
 * @method mixed sRandMember(string $key, int $count = 0)
 * @method array|false|Redis sUnion(string $key, string ...$other_keys)
 * @method false|int|Redis sUnionStore(string $dst, string $key, string ...$other_keys)
 * @method bool|Redis save()
 * @method false|int|Redis scard(string $key)
 * @method mixed script(string $command, mixed ...$args)
 * @method bool|Redis select(int $db)
 * @method false|string serverName()
 * @method false|string serverVersion()
 * @method false|int|Redis setBit(string $key, int $idx, bool $value)
 * @method false|int|Redis setRange(string $key, int $index, string $value)
 * @method bool setOption(int $option, mixed $value)
 * @method void setex(string $key, int $expire, mixed $value)
 * @method bool|Redis sismember(string $key, mixed $value)
 * @method bool|Redis replicaof(string|null $host = null, int $port = 6379)
 * @method false|int|Redis touch(array|string $key_or_array, string ...$more_keys)
 * @method mixed slowlog(string $operation, int $length = 0)
 * @method mixed sort(string $key, array|null $options = null)
 * @method mixed sort_ro(string $key, array|null $options = null)
 * @method false|int|Redis srem(string $key, mixed $value, mixed ...$other_values)
 * @method bool ssubscribe(array $channels, callable $cb)
 * @method false|int|Redis strlen(string $key)
 * @method void subscribe(array|string $channels, \Closure $callback)
 * @method array|bool|Redis sunsubscribe(array $channels)
 * @method bool|Redis swapdb(int $src, int $dst)
 * @method array|Redis time()
 * @method false|int|Redis ttl(string $key)
 * @method false|int|Redis type(string $key)
 * @method false|int|Redis unlink(array|string $key, string ...$other_keys)
 * @method array|bool|Redis unsubscribe(array $channels)
 * @method bool|Redis unwatch()
 * @method false|int|Redis vadd(string $key, array $values, mixed $element, array|null $options = null)
 * @method false|int|Redis vcard(string $key)
 * @method false|int|Redis vdim(string $key)
 * @method array|false|Redis vemb(string $key, mixed $member, bool $raw = false)
 * @method array|false|Redis|string vgetattr(string $key, mixed $member, bool $decode = true)
 * @method array|false|Redis vinfo(string $key)
 * @method bool|Redis vismember(string $key, mixed $member)
 * @method array|false|Redis vlinks(string $key, mixed $member, bool $withscores = false)
 * @method array|false|Redis|string vrandmember(string $key, int $count = 0)
 * @method array|false|Redis vrange(string $key, string $min, string $max, int $count = -1)
 * @method false|int|Redis vrem(string $key, mixed $member)
 * @method false|int|Redis vsetattr(string $key, mixed $member, array|string $attributes)
 * @method array|false|Redis vsim(string $key, mixed $member, array|null $options = null)
 * @method bool|Redis watch(array|string $key, string ...$other_keys)
 * @method false|int wait(int $numreplicas, int $timeout)
 * @method false|int xack(string $key, string $group, array $ids)
 * @method false|Redis|string xadd(string $key, string $id, array $values, int $maxlen = 0, bool $approx = false, bool $nomkstream = false)
 * @method array|bool|Redis xautoclaim(string $key, string $group, string $consumer, int $min_idle, string $start, int $count = -1, bool $justid = false)
 * @method array|bool|Redis xclaim(string $key, string $group, string $consumer, int $min_idle, array $ids, array $options)
 * @method false|int|Redis xdel(string $key, array $ids)
 * @method array|false|Redis xdelex(string $key, array $ids, string|null $mode = null)
 * @method mixed xgroup(string $operation, string|null $key = null, string|null $group = null, string|null $id_or_consumer = null, bool $mkstream = false, int $entries_read = -2)
 * @method mixed xinfo(string $operation, string|null $arg1 = null, string|null $arg2 = null, int $count = -1)
 * @method false|int|Redis xlen(string $key)
 * @method array|false|Redis xpending(string $key, string $group, string|null $start = null, string|null $end = null, int $count = -1, string|null $consumer = null)
 * @method array|bool|Redis xrange(string $key, string $start, string $end, int $count = -1)
 * @method array|bool|Redis xread(array $streams, int $count = -1, int $block = -1)
 * @method array|bool|Redis xreadgroup(string $group, string $consumer, array $streams, int $count = 1, int $block = 1)
 * @method array|bool|Redis xrevrange(string $key, string $end, string $start, int $count = -1)
 * @method false|int|Redis xtrim(string $key, string $threshold, bool $approx = false, bool $minid = false, int $limit = -1)
 * @method false|float|int|Redis zAdd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems)
 * @method false|int|Redis zCard(string $key)
 * @method false|int|Redis zCount(string $key, int|string $start, int|string $end)
 * @method false|float|Redis zIncrBy(string $key, float $value, mixed $member)
 * @method false|int|Redis zLexCount(string $key, string $min, string $max)
 * @method array|false|Redis zMscore(string $key, mixed $member, mixed ...$other_members)
 * @method array|false|Redis zPopMax(string $key, int|null $count = null)
 * @method array|false|Redis zPopMin(string $key, int|null $count = null)
 * @method array|false|Redis zRange(string $key, string|int $start, string|int $end, array|bool|null $options = null)
 * @method array|false|Redis zRangeByLex(string $key, string $min, string $max, int $offset = -1, int $count = -1)
 * @method array|false|Redis zRangeByScore(string $key, string $start, string $end, array $options = [])
 * @method false|int|Redis zrangestore(string $dstkey, string $srckey, string $start, string $end, array|bool|null $options = null)
 * @method array|Redis|string zRandMember(string $key, array|null $options = null)
 * @method false|int|Redis zRank(string $key, mixed $member)
 * @method false|int|Redis zRem(mixed $key, mixed $member, mixed ...$other_members)
 * @method false|int|Redis zRemRangeByLex(string $key, string $min, string $max)
 * @method false|int|Redis zRemRangeByRank(string $key, int $start, int $end)
 * @method false|int|Redis zRemRangeByScore(string $key, string $start, string $end)
 * @method array|false|Redis zRevRange(string $key, int $start, int $end, mixed $scores = null)
 * @method array|false|Redis zRevRangeByLex(string $key, string $max, string $min, int $offset = -1, int $count = -1)
 * @method array|false|Redis zRevRangeByScore(string $key, string $max, string $min, array|bool $options = [])
 * @method false|int|Redis zRevRank(string $key, mixed $member)
 * @method false|float|Redis zScore(string $key, mixed $member)
 * @method array|false|Redis zdiff(array $keys, array|null $options = null)
 * @method false|int|Redis zdiffstore(string $dst, array $keys)
 * @method array|false|Redis zinter(array $keys, array|null $weights = null, array|null $options = null)
 * @method false|int|Redis zintercard(array $keys, int $limit = -1)
 * @method array|false|Redis zunion(array $keys, array|null $weights = null, array|null $options = null)
 */
class RedisConnection extends BaseConnection
{
    protected Redis|RedisCluster|null $connection = null;

    protected ?Dispatcher $eventDispatcher = null;

    protected array $config = [
        'timeout' => 0.0,
        'reserved' => null,
        'retry_interval' => 0,
        'read_timeout' => 0.0,
        'cluster' => [
            'enable' => false,
            'name' => null,
            'seeds' => [],
            'read_timeout' => 0.0,
            'persistent' => false,
            'context' => [],
        ],
        'sentinel' => [
            'enable' => false,
            'master_name' => '',
            'nodes' => [],
            'persistent' => '',
            'read_timeout' => 0,
        ],
        'options' => [],
        'context' => [],
        'event' => [
            'enable' => false,
        ],
    ];

    /**
     * Current redis database.
     */
    protected ?int $database = null;

    /**
     * Determine if the connection calls should be transformed to Laravel style.
     */
    protected bool $shouldTransform = false;

    /**
     * Create a new Redis connection instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(Container $container, PoolInterface $pool, array $config)
    {
        parent::__construct($container, $pool);
        $this->config = array_replace_recursive($this->config, $config);

        $this->reconnect();
    }

    public function __call($name, $arguments)
    {
        try {
            return $this->executeCommand($name, $arguments);
        } catch (RedisException $exception) {
            return $this->retry($name, $arguments, $exception);
        }
    }

    /**
     * Execute a Redis command, applying transforms when enabled.
     *
     * @param array<int, mixed> $arguments
     */
    private function executeCommand(string $name, array $arguments): mixed
    {
        if (in_array($name, ['subscribe', 'psubscribe'], true)) {
            return $this->callSubscribe($name, $arguments);
        }

        if ($this->shouldTransform && ! $this->isQueueingMode()) {
            $method = 'call' . ucfirst($name);
            if (method_exists($this, $method)) {
                return $this->{$method}(...$arguments);
            }
        }

        return $this->connection->{$name}(...$arguments);
    }

    /**
     * Get the active connection.
     */
    public function getActiveConnection(): static
    {
        if ($this->check()) {
            return $this;
        }

        if (! $this->reconnect()) {
            throw new ConnectionException('Connection reconnect failed.');
        }

        return $this;
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): ?Dispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * Reconnect to Redis.
     *
     * @throws RedisException
     * @throws ConnectionException
     */
    public function reconnect(): bool
    {
        $auth = $this->config['auth'] ?? null;
        $db = (int) ($this->config['db'] ?? 0);
        $cluster = $this->config['cluster']['enable'] ?? false;
        $sentinel = $this->config['sentinel']['enable'] ?? false;

        $redis = match (true) {
            $cluster => $this->createRedisCluster(),
            $sentinel => $this->createRedisSentinel(),
            default => $this->createRedis($this->config),
        };

        $options = $this->config['options'] ?? [];

        foreach ($options as $name => $value) {
            if (is_string($name)) {
                $name = match (strtolower($name)) {
                    'serializer' => Redis::OPT_SERIALIZER,
                    'prefix' => Redis::OPT_PREFIX,
                    'read_timeout' => Redis::OPT_READ_TIMEOUT,
                    'scan' => Redis::OPT_SCAN,
                    'failover' => defined(Redis::class . '::OPT_SLAVE_FAILOVER') ? Redis::OPT_SLAVE_FAILOVER : 5,
                    'keepalive' => Redis::OPT_TCP_KEEPALIVE,
                    'compression' => Redis::OPT_COMPRESSION,
                    'reply_literal' => Redis::OPT_REPLY_LITERAL,
                    'compression_level' => Redis::OPT_COMPRESSION_LEVEL,
                    default => throw new InvalidRedisOptionException(sprintf('The redis option key `%s` is invalid.', $name)),
                };
            }

            $redis->setOption($name, $value);
        }

        if ($redis instanceof Redis && isset($auth) && $auth !== '') {
            $redis->auth($auth);
        }

        $database = $this->database ?? $db;
        if ($database > 0) {
            $redis->select($database);
        }

        $this->connection = $redis;
        $this->lastUseTime = microtime(true);

        if (($this->config['event']['enable'] ?? false) && $this->container->has(Dispatcher::class)) {
            $this->eventDispatcher = $this->container->make(Dispatcher::class);
        }

        return true;
    }

    /**
     * Close the current connection.
     */
    public function close(): bool
    {
        $this->connection = null;

        return true;
    }

    /**
     * Release the connection back to pool.
     */
    public function release(): void
    {
        $this->shouldTransform = false;

        try {
            $defaultDb = (int) ($this->config['db'] ?? 0);
            if ($this->database !== null && $this->database !== $defaultDb) {
                $this->select($defaultDb);
                $this->database = null;
            }

            parent::release();
        } catch (Throwable $exception) {
            $this->log('Release connection failed, caused by ' . $exception, LogLevel::CRITICAL);
        }
    }

    /**
     * Set current redis database.
     */
    public function setDatabase(?int $database): void
    {
        $this->database = $database;
    }

    /**
     * Create a redis cluster connection.
     */
    protected function createRedisCluster(): RedisCluster
    {
        try {
            $parameters = [];
            $parameters[] = $this->config['cluster']['name'] ?? null;
            $parameters[] = $this->config['cluster']['seeds'] ?? [];
            $parameters[] = $this->config['timeout'] ?? 0.0;
            $parameters[] = $this->config['cluster']['read_timeout'] ?? 0.0;
            $parameters[] = $this->config['cluster']['persistent'] ?? false;
            $parameters[] = $this->config['auth'] ?? null;
            if (! empty($this->config['cluster']['context'])) {
                $parameters[] = $this->config['cluster']['context'];
            }

            $redis = new RedisCluster(...$parameters);
        } catch (Throwable $exception) {
            throw new ConnectionException('Connection reconnect failed ' . $exception->getMessage());
        }

        return $redis;
    }

    /**
     * Retry a redis command after reconnecting.
     *
     * @param array<int, mixed> $arguments
     */
    protected function retry(string $name, array $arguments, RedisException $exception): mixed
    {
        $this->log('Redis::__call failed, because ' . $exception->getMessage());

        try {
            $this->reconnect();

            return $this->executeCommand($name, $arguments);
        } catch (Throwable $exception) {
            $this->lastUseTime = 0.0;
            throw $exception;
        }
    }

    /**
     * Determine if the underlying Redis client is in pipeline/multi mode.
     */
    protected function isQueueingMode(): bool
    {
        return $this->connection instanceof Redis && $this->connection->getMode() !== Redis::ATOMIC;
    }

    /**
     * Create a redis sentinel connection.
     *
     * @throws ConnectionException
     */
    protected function createRedisSentinel(): Redis
    {
        try {
            $nodes = $this->config['sentinel']['nodes'] ?? [];
            $timeout = $this->config['timeout'] ?? 0;
            $persistent = $this->config['sentinel']['persistent'] ?? null;
            $retryInterval = $this->config['retry_interval'] ?? 0;
            $readTimeout = $this->config['sentinel']['read_timeout'] ?? 0;
            $masterName = $this->config['sentinel']['master_name'] ?? '';
            $auth = $this->config['sentinel']['auth'] ?? null;

            shuffle($nodes);

            $host = null;
            $port = null;
            foreach ($nodes as $node) {
                try {
                    $resolved = parse_url($node);
                    if (! isset($resolved['host'], $resolved['port'])) {
                        $this->log(sprintf('The redis sentinel node [%s] is invalid.', $node), LogLevel::ERROR);
                        continue;
                    }

                    $options = [
                        'host' => $resolved['host'],
                        'port' => (int) $resolved['port'],
                        'connectTimeout' => $timeout,
                        'persistent' => $persistent,
                        'retryInterval' => $retryInterval,
                        'readTimeout' => $readTimeout,
                        ...($auth ? ['auth' => $auth] : []),
                    ];

                    $sentinel = $this->container->make(RedisSentinelFactory::class)->create($options);
                    $masterInfo = $sentinel->getMasterAddrByName($masterName);
                    if (is_array($masterInfo) && count($masterInfo) >= 2) {
                        [$host, $port] = $masterInfo;
                        break;
                    }
                } catch (Throwable $exception) {
                    $this->log('Redis sentinel connection failed, caused by ' . $exception->getMessage());
                    continue;
                }
            }

            if ($host === null && $port === null) {
                throw new InvalidRedisConnectionException('Connect sentinel redis server failed.');
            }

            $redis = $this->createRedis([
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'retry_interval' => $retryInterval,
                'read_timeout' => $readTimeout,
            ]);
        } catch (Throwable $exception) {
            throw new ConnectionException('Connection reconnect failed ' . $exception->getMessage());
        }

        return $redis;
    }

    /**
     * Create a redis connection.
     *
     * @param array<string, mixed> $config
     * @throws ConnectionException
     * @throws RedisException
     */
    protected function createRedis(array $config): Redis
    {
        $parameters = [
            $config['host'],
            (int) $config['port'],
            $config['timeout'] ?? 0.0,
            $config['reserved'] ?? null,
            $config['retry_interval'] ?? 0,
            $config['read_timeout'] ?? 0.0,
        ];

        if (! empty($config['context'])) {
            $parameters[] = $config['context'];
        }

        $redis = new Redis();
        if (! $redis->connect(...$parameters)) {
            throw new ConnectionException('Connection reconnect failed.');
        }

        return $redis;
    }

    /**
     * Log a redis connection message.
     */
    protected function log(string $message, string $level = LogLevel::WARNING): void
    {
        if ($this->container->has(StdoutLoggerInterface::class)) {
            $this->container->make(StdoutLoggerInterface::class)->log($level, $message);
        }
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
     * Removes and returns random elements from the set value at key.
     *
     * When called without count, returns a single element (string|false).
     * When called with count, returns an array of elements.
     */
    protected function callSpop(string $key, ?int $count = null): mixed
    {
        if ($count !== null) {
            return $this->connection->sPop($key, $count);
        }

        return $this->connection->sPop($key);
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
            return $this->__call('scan', array_merge([&$cursor], $arguments));
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
            return $this->__call('zScan', array_merge([$key, &$cursor], $arguments));
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
            return $this->__call('hScan', array_merge([$key, &$cursor], $arguments));
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
            return $this->__call('sScan', array_merge([$key, &$cursor], $arguments));
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

    /**
     * Execute a subscribe or psubscribe command.
     *
     * WARNING: phpredis subscribe/psubscribe blocks the calling coroutine for
     * the lifetime of the subscription. This holds a connection pool slot until
     * the subscription ends. Always run in a dedicated coroutine and be mindful
     * of pool size when using multiple subscribers.
     *
     * @TODO Explore non-blocking alternatives such as Swoole\Coroutine\Redis
     *       or a channel-based subscriber that doesn't hold a pool connection.
     */
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

    /**
     * Build the arguments for a subscribe or psubscribe call.
     *
     * Wraps the user callback to reorder phpredis's callback arguments
     * from ($redis, $channel, $message) to Laravel's ($message, $channel).
     * For psubscribe, phpredis sends ($redis, $pattern, $channel, $message).
     */
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
            fn ($redis, $pattern, $channel, $message) => $callback($message, $channel),
        ];
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

    /**
     * Get the underlying Redis client instance.
     *
     * @return Redis|RedisCluster
     */
    public function client(): mixed
    {
        return $this->connection;
    }

    /**
     * Determine if the connection is to a Redis Cluster.
     */
    public function isCluster(): bool
    {
        return $this->connection instanceof RedisCluster;
    }

    /**
     * Execute a Lua script using evalSha with automatic fallback to eval.
     *
     * Redis caches compiled Lua scripts by SHA1 hash. This method tries evalSha
     * first (uses cached compiled script), and falls back to eval if the script
     * isn't cached yet (NOSCRIPT error).
     *
     * Unlike naive implementations that treat any `false` return as NOSCRIPT,
     * this method properly distinguishes NOSCRIPT errors from other failures
     * (syntax errors, OOM, WRONGTYPE, etc.) and throws on non-NOSCRIPT errors.
     *
     * @param string $script The Lua script to execute
     * @param array<string> $keys Redis keys (passed as KEYS[] in Lua)
     * @param array<mixed> $args Additional arguments (passed as ARGV[] in Lua)
     * @return mixed The script's return value
     *
     * @throws LuaScriptException If script execution fails (non-NOSCRIPT error)
     */
    public function evalWithShaCache(string $script, array $keys = [], array $args = []): mixed
    {
        $sha = sha1($script);
        $numKeys = count($keys);

        // phpredis signature: evalSha(sha, combined_args, num_keys)
        // combined_args = keys first, then other args
        $combinedArgs = [...$keys, ...$args];

        // Clear any stale error from previous commands to ensure getLastError()
        // reflects this call, not a previous one
        $this->connection->clearLastError();

        // Try evalSha first - uses cached compiled script
        $result = $this->connection->evalSha($sha, $combinedArgs, $numKeys);

        if ($result === false) {
            $error = $this->connection->getLastError();

            // NOSCRIPT means script not cached yet - fall back to eval
            if ($error !== null && str_contains($error, 'NOSCRIPT')) {
                $this->connection->clearLastError();
                $result = $this->connection->eval($script, $combinedArgs, $numKeys);

                if ($result === false) {
                    $evalError = $this->connection->getLastError();
                    if ($evalError !== null) {
                        throw new LuaScriptException('Lua script execution failed: ' . $evalError);
                    }
                    // If no error, script legitimately returned nil (which becomes false)
                }
            } elseif ($error !== null) {
                // Some other error (syntax, OOM, WRONGTYPE, etc.)
                throw new LuaScriptException('Lua script execution failed: ' . $error);
            }
            // If $error is null and $result is false, the script legitimately returned false
        }

        return $result;
    }

    /**
     * Safely scan the Redis keyspace for keys matching a pattern.
     *
     * This method handles the phpredis OPT_PREFIX complexity correctly:
     * - Automatically prepends OPT_PREFIX to the scan pattern
     * - Strips OPT_PREFIX from returned keys so they work with other commands
     *
     * @param string $pattern The pattern to match (e.g., "cache:users:*").
     *                        Should NOT include OPT_PREFIX - it's handled automatically.
     * @param int $count The COUNT hint for SCAN (not a limit, just a hint to Redis)
     * @return Generator<string> Yields keys with OPT_PREFIX stripped
     */
    public function safeScan(string $pattern, int $count = 1000): Generator
    {
        $optPrefix = (string) $this->connection->getOption(Redis::OPT_PREFIX);

        return (new SafeScan($this, $optPrefix))->execute($pattern, $count);
    }

    /**
     * Flush (delete) all Redis keys matching a pattern.
     *
     * Use this when you already have a connection (e.g., inside withConnection()
     * or when doing multiple operations on the same connection). No connection
     * lifecycle overhead since you're operating on an existing connection.
     *
     * For standalone/one-off operations, use Redis::flushByPattern() instead,
     * which handles connection lifecycle automatically.
     *
     * Uses SCAN to iterate keys efficiently and deletes them in batches.
     * Correctly handles OPT_PREFIX to avoid the double-prefixing bug.
     *
     * @param string $pattern The pattern to match (e.g., "cache:test:*").
     *                        Should NOT include OPT_PREFIX - it's handled automatically.
     * @return int Number of keys deleted
     */
    public function flushByPattern(string $pattern): int
    {
        return (new FlushByPattern($this))->execute($pattern);
    }
}
