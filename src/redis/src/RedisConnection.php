<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use BadMethodCallException;
use Generator;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Pool\Connection as BaseConnection;
use Hypervel\Pool\Exceptions\ConnectionException;
use Hypervel\Pool\PoolOption;
use Hypervel\Redis\Exceptions\InvalidRedisOptionException;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\Operations\FlushByPattern;
use Hypervel\Redis\Operations\SafeScan;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Macroable;
use Psr\Log\LogLevel;
use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\go;

/**
 * Abstract base class for pooled Redis connections with Laravel-style method transformations.
 *
 * @method mixed get(string $key) Get the value of a key
 * @method mixed set(string $key, mixed $value, mixed $expireResolution = null, int|null $expireTTL = null, string|null $flag = null) Set the value of a key
 * @method array|false|Redis|RedisCluster mget(array $keys) Get the values of multiple keys
 * @method bool|int|Redis|RedisCluster setnx(string $key, mixed $value) Set key if not exists
 * @method bool|int|Redis|RedisCluster setNx(string $key, mixed $value) Set key if not exists
 * @method array|false|Redis|RedisCluster hmget(string $key, array $fields) Get hash field values
 * @method bool|Redis|RedisCluster hmset(string $key, array $fieldValues) Set hash field values
 * @method bool|int|Redis|RedisCluster hsetnx(string $hash, string $key, mixed $value) Set hash field if not exists
 * @method mixed hget(string $key, string $member) Get hash field value
 * @method false|int|Redis|RedisCluster hset(string $key, mixed ...$fields_and_vals) Set hash field values
 * @method false|int lrem(string $key, int $count, mixed $value) Remove list elements
 * @method false|int|Redis|RedisCluster llen(string $key) Get list length
 * @method null|array|false|Redis|RedisCluster blpop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args) Blocking left pop from list
 * @method null|array|false|Redis|RedisCluster brpop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args) Blocking right pop from list
 * @method mixed spop(string $key, int $count = 0) Remove and return random set member
 * @method false|int|Redis|RedisCluster sRem(string $key, mixed $value, mixed ...$other_values) Remove members from set
 * @method false|float|int|Redis|RedisCluster zadd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems) Add members to sorted set
 * @method false|int|Redis|RedisCluster zcard(string $key) Get sorted set cardinality
 * @method false|int|Redis|RedisCluster zcount(string $key, int|string $start, int|string $end) Count sorted set members by score range
 * @method array|false|Redis|RedisCluster zrangebyscore(string $key, float|int|string $min, float|int|string $max, array $options = []) Get sorted set members by score range
 * @method array|false|Redis|RedisCluster zrevrangebyscore(string $key, float|int|string $max, float|int|string $min, array $options = []) Get sorted set members by score range (reverse)
 * @method false|int|Redis|RedisCluster zinterstore(string $output, array $keys, array $options = []) Intersect sorted sets
 * @method false|int|Redis|RedisCluster zunionstore(string $output, array $keys, array $options = []) Union sorted sets
 * @method mixed eval(string $script, int $numberOfKeys, mixed ...$arguments) Evaluate Lua script
 * @method mixed evalsha(string $script, int $numkeys, mixed ...$arguments) Evaluate Lua script by SHA1
 * @method mixed flushdb(mixed ...$arguments) Flush database
 * @method mixed executeRaw(array $parameters) Execute raw Redis command
 * @method mixed pipeline(callable|null $callback = null) Execute commands in a pipeline
 * @method array|false|Redis|RedisCluster smembers(string $key) Get all set members
 * @method false|int|Redis|RedisCluster hdel(string $key, string $field, string ...$other_fields) Delete hash fields
 * @method false|int|Redis|RedisCluster zrem(mixed $key, mixed $member, mixed ...$other_members) Remove sorted set members
 * @method false|int|Redis|RedisCluster hlen(string $key) Get number of hash fields
 * @method array|false|Redis|RedisCluster hkeys(string $key) Get all hash field names
 * @method string _serialize(mixed $value) Serialize a value using configured serializer
 * @method string _digest(mixed $value)
 * @method string _pack(mixed $value)
 * @method mixed _unpack(string $value)
 * @method mixed acl(string $subcmd, string ...$args)
 * @method false|int|Redis|RedisCluster append(string $key, mixed $value)
 * @method bool|Redis auth(mixed $credentials)
 * @method bool|Redis|RedisCluster bgSave()
 * @method bool|Redis|RedisCluster bgrewriteaof()
 * @method array|false|Redis|RedisCluster waitaof(int $numlocal, int $numreplicas, int $timeout)
 * @method false|int|Redis|RedisCluster bitcount(string $key, int $start = 0, int $end = -1, bool $bybit = false)
 * @method false|int|Redis|RedisCluster bitop(string $operation, string $deskey, string $srckey, string ...$other_keys)
 * @method false|int|Redis|RedisCluster bitpos(string $key, bool $bit, int $start = 0, int $end = -1, bool $bybit = false)
 * @method null|array|false|Redis|RedisCluster blPop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args) Blocking left pop from list
 * @method null|array|false|Redis|RedisCluster brPop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args) Blocking right pop from list
 * @method false|Redis|RedisCluster|string brpoplpush(string $src, string $dst, float|int $timeout)
 * @method array|false|Redis|RedisCluster bzPopMax(array|string $key, int|string $timeout_or_key, mixed ...$extra_args)
 * @method array|false|Redis|RedisCluster bzPopMin(array|string $key, int|string $timeout_or_key, mixed ...$extra_args)
 * @method null|array|false|Redis|RedisCluster bzmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method null|array|false|Redis|RedisCluster zmpop(array $keys, string $from, int $count = 1)
 * @method null|array|false|Redis|RedisCluster blmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method null|array|false|Redis|RedisCluster lmpop(array $keys, string $from, int $count = 1)
 * @method bool clearLastError()
 * @method mixed client(string $opt = '', mixed ...$args)
 * @method mixed command(string|null $opt = null, mixed ...$args)
 * @method mixed config(string $operation, array|string|null $key_or_settings = null, string|null $value = null)
 * @method bool connect(string $host, int $port = 6379, float $timeout = 0, string|null $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, array|null $context = null)
 * @method bool|Redis|RedisCluster copy(string $src, string $dst, array|null $options = null)
 * @method false|int|Redis|RedisCluster dbSize()
 * @method Redis|string debug(string $key)
 * @method false|int|Redis|RedisCluster decr(string $key, int $by = 1)
 * @method false|int|Redis|RedisCluster decrBy(string $key, int $value)
 * @method false|int|Redis|RedisCluster del(array|string $key, string ...$other_keys)
 * @method false|int|Redis|RedisCluster delex(string $key, array|null $options = null)
 * @method false|int|Redis|RedisCluster delifeq(string $key, mixed $value)
 * @method false|Redis|RedisCluster|string digest(string $key)
 * @method false|Redis|RedisCluster|string dump(string $key)
 * @method false|Redis|RedisCluster|string echo(string $str)
 * @method mixed eval_ro(string $script_sha, array $args = [], int $num_keys = 0)
 * @method mixed evalsha_ro(string $sha1, array $args = [], int $num_keys = 0)
 * @method array|false|Redis exec()
 * @method bool|int|Redis|RedisCluster exists(mixed $key, mixed ...$other_keys)
 * @method bool|Redis|RedisCluster expire(string $key, int $timeout, string|null $mode = null)
 * @method bool|Redis|RedisCluster expireAt(string $key, int $timestamp, string|null $mode = null)
 * @method bool|Redis failover(array|null $to = null, bool $abort = false, int $timeout = 0)
 * @method false|int|Redis|RedisCluster expiretime(string $key)
 * @method false|int|Redis|RedisCluster pexpiretime(string $key)
 * @method mixed fcall(string $fn, array $keys = [], array $args = [])
 * @method mixed fcall_ro(string $fn, array $keys = [], array $args = [])
 * @method bool|Redis|RedisCluster flushAll(bool|null $sync = null)
 * @method mixed flushDB(mixed ...$arguments) Flush database
 * @method array|bool|Redis|string function(string $operation, mixed ...$args)
 * @method false|int|Redis|RedisCluster geoadd(string $key, float $lng, float $lat, string $member, mixed ...$other_triples_and_options)
 * @method false|float|Redis|RedisCluster geodist(string $key, string $src, string $dst, string|null $unit = null)
 * @method array|false|Redis|RedisCluster geohash(string $key, string $member, string ...$other_members)
 * @method array|false|Redis|RedisCluster geopos(string $key, string $member, string ...$other_members)
 * @method mixed georadius(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method mixed georadius_ro(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method mixed georadiusbymember(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method mixed georadiusbymember_ro(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method array geosearch(string $key, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method array|false|int|Redis|RedisCluster geosearchstore(string $dst, string $src, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method mixed getAuth()
 * @method false|int|Redis|RedisCluster getBit(string $key, int $idx)
 * @method bool|Redis|RedisCluster|string getEx(string $key, array $options = [])
 * @method int getDBNum()
 * @method bool|Redis|RedisCluster|string getDel(string $key)
 * @method string getHost()
 * @method null|string getLastError()
 * @method int getMode()
 * @method mixed getOption(int $option)
 * @method null|string getPersistentID()
 * @method int getPort()
 * @method false|Redis|RedisCluster|string getRange(string $key, int $start, int $end)
 * @method array|false|int|Redis|RedisCluster|string lcs(string $key1, string $key2, array|null $options = null)
 * @method float getReadTimeout()
 * @method false|Redis|RedisCluster|string getset(string $key, mixed $value)
 * @method false|float getTimeout()
 * @method array getTransferredBytes()
 * @method void clearTransferredBytes()
 * @method array|false|Redis|RedisCluster getWithMeta(string $key)
 * @method false|int|Redis|RedisCluster hDel(string $key, string $field, string ...$other_fields) Delete hash fields
 * @method array|false|Redis|RedisCluster hexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method array|false|Redis|RedisCluster hpexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method array|false|Redis|RedisCluster hexpireat(string $key, int $time, array $fields, string|null $mode = null)
 * @method array|false|Redis|RedisCluster hpexpireat(string $key, int $mstime, array $fields, string|null $mode = null)
 * @method array|false|Redis|RedisCluster httl(string $key, array $fields)
 * @method array|false|Redis|RedisCluster hpttl(string $key, array $fields)
 * @method array|false|Redis|RedisCluster hexpiretime(string $key, array $fields)
 * @method array|false|Redis|RedisCluster hpexpiretime(string $key, array $fields)
 * @method array|false|Redis|RedisCluster hpersist(string $key, array $fields)
 * @method bool|Redis|RedisCluster hExists(string $key, string $field)
 * @method mixed hGet(string $key, string $member) Get hash field value
 * @method array|false|Redis|RedisCluster hGetAll(string $key)
 * @method mixed hGetWithMeta(string $key, string $member)
 * @method array|false|Redis|RedisCluster hgetdel(string $key, array $fields)
 * @method array|false|Redis|RedisCluster hgetex(string $key, array $fields, string|array|null $expiry = null)
 * @method false|int|Redis|RedisCluster hIncrBy(string $key, string $field, int $value)
 * @method false|float|Redis|RedisCluster hIncrByFloat(string $key, string $field, float $value)
 * @method array|false|Redis|RedisCluster hKeys(string $key) Get all hash field names
 * @method false|int|Redis|RedisCluster hLen(string $key) Get number of hash fields
 * @method array|false|Redis|RedisCluster hMget(string $key, array $fields) Get hash field values
 * @method bool|Redis|RedisCluster hMset(string $key, array $fieldValues) Set hash field values
 * @method array|false|Redis|RedisCluster|string hRandField(string $key, array|null $options = null)
 * @method false|int|Redis|RedisCluster hSet(string $key, mixed ...$fields_and_vals) Set hash field values
 * @method bool|int|Redis|RedisCluster hSetNx(string $hash, string $key, mixed $value) Set hash field if not exists
 * @method false|int|Redis|RedisCluster hsetex(string $key, array $fields, array|null $expiry = null)
 * @method false|int|Redis|RedisCluster hStrLen(string $key, string $field)
 * @method array|false|Redis|RedisCluster hVals(string $key)
 * @method false|int|Redis|RedisCluster incr(string $key, int $by = 1)
 * @method false|int|Redis|RedisCluster incrBy(string $key, int $value)
 * @method false|float|Redis|RedisCluster incrByFloat(string $key, float $value)
 * @method array|false|Redis|RedisCluster info(string ...$sections)
 * @method bool isConnected()
 * @method array|false|Redis|RedisCluster keys(string $pattern)
 * @method false|int|Redis|RedisCluster lInsert(string $key, string $pos, mixed $pivot, mixed $value)
 * @method false|int|Redis|RedisCluster lLen(string $key) Get list length
 * @method false|Redis|RedisCluster|string lMove(string $src, string $dst, string $wherefrom, string $whereto)
 * @method false|Redis|RedisCluster|string blmove(string $src, string $dst, string $wherefrom, string $whereto, float $timeout)
 * @method array|bool|Redis|RedisCluster|string lPop(string $key, int $count = 0)
 * @method null|array|bool|int|Redis|RedisCluster lPos(string $key, mixed $value, array|null $options = null)
 * @method false|int|Redis|RedisCluster lPush(string $key, mixed ...$elements)
 * @method false|int|Redis|RedisCluster rPush(string $key, mixed ...$elements)
 * @method false|int|Redis|RedisCluster lPushx(string $key, mixed $value)
 * @method false|int|Redis|RedisCluster rPushx(string $key, mixed $value)
 * @method bool|Redis|RedisCluster lSet(string $key, int $index, mixed $value)
 * @method int lastSave()
 * @method mixed lindex(string $key, int $index)
 * @method array|false|Redis|RedisCluster lrange(string $key, int $start, int $end)
 * @method bool|Redis|RedisCluster ltrim(string $key, int $start, int $end)
 * @method bool|Redis migrate(string $host, int $port, array|string $key, int $dstdb, int $timeout, bool $copy = false, bool $replace = false, mixed $credentials = null)
 * @method bool|Redis move(string $key, int $index)
 * @method bool|Redis|RedisCluster mset(array $key_values)
 * @method false|int|Redis|RedisCluster msetex(array $key_values, int|float|array|null $expiry = null)
 * @method bool|Redis|RedisCluster msetnx(array $key_values)
 * @method bool|Redis|RedisCluster multi(int $value = 1)
 * @method false|int|Redis|RedisCluster|string object(string $subcommand, string $key)
 * @method bool pconnect(string $host, int $port = 6379, float $timeout = 0, string|null $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, array|null $context = null)
 * @method bool|Redis|RedisCluster persist(string $key)
 * @method bool pexpire(string $key, int $timeout, string|null $mode = null)
 * @method bool|Redis|RedisCluster pexpireAt(string $key, int $timestamp, string|null $mode = null)
 * @method int|Redis|RedisCluster pfadd(string $key, array $elements)
 * @method false|int|Redis|RedisCluster pfcount(array|string $key_or_keys)
 * @method bool|Redis|RedisCluster pfmerge(string $dst, array $srckeys)
 * @method bool|Redis|RedisCluster|string ping(string|null $message = null)
 * @method bool|Redis|RedisCluster psetex(string $key, int $expire, mixed $value)
 * @method false|int|Redis|RedisCluster pttl(string $key)
 * @method false|int|Redis|RedisCluster publish(string $channel, string $message)
 * @method mixed pubsub(string $command, mixed $arg = null)
 * @method array|bool|Redis punsubscribe(array $patterns)
 * @method array|bool|Redis|RedisCluster|string rPop(string $key, int $count = 0)
 * @method false|Redis|RedisCluster|string randomKey()
 * @method mixed rawcommand(string $command, mixed ...$args)
 * @method bool|Redis|RedisCluster rename(string $old_name, string $new_name)
 * @method bool|Redis|RedisCluster renameNx(string $key_src, string $key_dst)
 * @method bool|Redis|RedisCluster restore(string $key, int $ttl, string $value, array|null $options = null)
 * @method mixed role()
 * @method false|Redis|RedisCluster|string rpoplpush(string $srckey, string $dstkey)
 * @method false|int|Redis|RedisCluster sAdd(string $key, mixed $value, mixed ...$other_values)
 * @method int sAddArray(string $key, array $values)
 * @method array|false|Redis|RedisCluster sDiff(string $key, string ...$other_keys)
 * @method false|int|Redis|RedisCluster sDiffStore(string $dst, string $key, string ...$other_keys)
 * @method array|false|Redis|RedisCluster sInter(array|string $key, string ...$other_keys)
 * @method false|int|Redis|RedisCluster sintercard(array $keys, int $limit = -1)
 * @method false|int|Redis|RedisCluster sInterStore(array|string $key, string ...$other_keys)
 * @method array|false|Redis|RedisCluster sMembers(string $key) Get all set members
 * @method array|false|Redis|RedisCluster sMisMember(string $key, string $member, string ...$other_members)
 * @method bool|Redis|RedisCluster sMove(string $src, string $dst, mixed $value)
 * @method mixed sPop(string $key, int $count = 0) Remove and return random set member
 * @method mixed sRandMember(string $key, int $count = 0)
 * @method array|false|Redis|RedisCluster sUnion(string $key, string ...$other_keys)
 * @method false|int|Redis|RedisCluster sUnionStore(string $dst, string $key, string ...$other_keys)
 * @method bool|Redis|RedisCluster save()
 * @method false|int|Redis|RedisCluster scard(string $key)
 * @method mixed script(string $command, mixed ...$args)
 * @method bool|Redis select(int $db)
 * @method false|string serverName()
 * @method false|string serverVersion()
 * @method false|int|Redis|RedisCluster setBit(string $key, int $idx, bool $value)
 * @method false|int|Redis|RedisCluster setRange(string $key, int $index, string $value)
 * @method bool setOption(int $option, mixed $value)
 * @method bool|Redis|RedisCluster setex(string $key, int $expire, mixed $value)
 * @method bool|Redis|RedisCluster sismember(string $key, mixed $value)
 * @method bool|Redis replicaof(string|null $host = null, int $port = 6379)
 * @method false|int|Redis|RedisCluster touch(array|string $key_or_array, string ...$more_keys)
 * @method mixed slowlog(string $operation, int $length = 0)
 * @method mixed sort(string $key, array|null $options = null)
 * @method mixed sort_ro(string $key, array|null $options = null)
 * @method false|int|Redis|RedisCluster srem(string $key, mixed $value, mixed ...$other_values) Remove members from set
 * @method false|int|Redis|RedisCluster strlen(string $key)
 * @method array|bool|Redis sunsubscribe(array $channels)
 * @method bool|Redis swapdb(int $src, int $dst)
 * @method array|Redis|RedisCluster time()
 * @method false|int|Redis|RedisCluster ttl(string $key)
 * @method false|int|Redis|RedisCluster type(string $key)
 * @method false|int|Redis|RedisCluster unlink(array|string $key, string ...$other_keys)
 * @method array|bool|Redis unsubscribe(array $channels)
 * @method null|bool|Redis unwatch()
 * @method false|int|Redis|RedisCluster vadd(string $key, array $values, mixed $element, array|null $options = null)
 * @method false|int|Redis|RedisCluster vcard(string $key)
 * @method false|int|Redis|RedisCluster vdim(string $key)
 * @method array|false|Redis|RedisCluster vemb(string $key, mixed $member, bool $raw = false)
 * @method array|false|Redis|RedisCluster|string vgetattr(string $key, mixed $member, bool $decode = true)
 * @method array|false|Redis|RedisCluster vinfo(string $key)
 * @method bool|Redis|RedisCluster vismember(string $key, mixed $member)
 * @method array|false|Redis|RedisCluster vlinks(string $key, mixed $member, bool $withscores = false)
 * @method array|false|Redis|RedisCluster|string vrandmember(string $key, int $count = 0)
 * @method array|false|Redis|RedisCluster vrange(string $key, string $min, string $max, int $count = -1)
 * @method false|int|Redis|RedisCluster vrem(string $key, mixed $member)
 * @method false|int|Redis|RedisCluster vsetattr(string $key, mixed $member, array|string $attributes)
 * @method array|false|Redis|RedisCluster vsim(string $key, mixed $member, array|null $options = null)
 * @method bool|Redis|RedisCluster watch(array|string $key, string ...$other_keys)
 * @method false|int wait(int $numreplicas, int $timeout)
 * @method false|int xack(string $key, string $group, array $ids)
 * @method false|Redis|RedisCluster|string xadd(string $key, string $id, array $values, int $maxlen = 0, bool $approx = false, bool $nomkstream = false)
 * @method array|bool|Redis|RedisCluster xautoclaim(string $key, string $group, string $consumer, int $min_idle, string $start, int $count = -1, bool $justid = false)
 * @method array|bool|Redis|RedisCluster xclaim(string $key, string $group, string $consumer, int $min_idle, array $ids, array $options)
 * @method false|int|Redis|RedisCluster xdel(string $key, array $ids)
 * @method array|false|Redis|RedisCluster xdelex(string $key, array $ids, string|null $mode = null)
 * @method mixed xgroup(string $operation, string|null $key = null, string|null $group = null, string|null $id_or_consumer = null, bool $mkstream = false, int $entries_read = -2)
 * @method mixed xinfo(string $operation, string|null $arg1 = null, string|null $arg2 = null, int $count = -1)
 * @method false|int|Redis|RedisCluster xlen(string $key)
 * @method array|false|Redis|RedisCluster xpending(string $key, string $group, string|null $start = null, string|null $end = null, int $count = -1, string|null $consumer = null)
 * @method array|bool|Redis|RedisCluster xrange(string $key, string $start, string $end, int $count = -1)
 * @method array|bool|Redis|RedisCluster xread(array $streams, int $count = -1, int $block = -1)
 * @method array|bool|Redis|RedisCluster xreadgroup(string $group, string $consumer, array $streams, int $count = 1, int $block = 1)
 * @method array|bool|Redis|RedisCluster xrevrange(string $key, string $end, string $start, int $count = -1)
 * @method false|int|Redis|RedisCluster xtrim(string $key, string $threshold, bool $approx = false, bool $minid = false, int $limit = -1)
 * @method false|float|int|Redis|RedisCluster zAdd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems) Add members to sorted set
 * @method false|int|Redis|RedisCluster zCard(string $key) Get sorted set cardinality
 * @method false|int|Redis|RedisCluster zCount(string $key, int|string $start, int|string $end) Count sorted set members by score range
 * @method false|float|Redis|RedisCluster zIncrBy(string $key, float $value, mixed $member)
 * @method false|int|Redis|RedisCluster zLexCount(string $key, string $min, string $max)
 * @method array|false|Redis|RedisCluster zMscore(string $key, mixed $member, mixed ...$other_members)
 * @method array|false|Redis|RedisCluster zPopMax(string $key, int|null $count = null)
 * @method array|false|Redis|RedisCluster zPopMin(string $key, int|null $count = null)
 * @method array|false|Redis|RedisCluster zRange(string $key, string|int $start, string|int $end, array|bool|null $options = null)
 * @method array|false|Redis|RedisCluster zRangeByLex(string $key, string $min, string $max, int $offset = -1, int $count = -1)
 * @method array|false|Redis|RedisCluster zRangeByScore(string $key, float|int|string $min, float|int|string $max, array $options = []) Get sorted set members by score range
 * @method false|int|Redis|RedisCluster zrangestore(string $dstkey, string $srckey, string $start, string $end, array|bool|null $options = null)
 * @method array|Redis|RedisCluster|string zRandMember(string $key, array|null $options = null)
 * @method false|int|Redis|RedisCluster zRank(string $key, mixed $member)
 * @method false|int|Redis|RedisCluster zRem(mixed $key, mixed $member, mixed ...$other_members) Remove sorted set members
 * @method false|int|Redis|RedisCluster zRemRangeByLex(string $key, string $min, string $max)
 * @method false|int|Redis|RedisCluster zRemRangeByRank(string $key, int $start, int $end)
 * @method false|int|Redis|RedisCluster zRemRangeByScore(string $key, string $start, string $end)
 * @method array|false|Redis|RedisCluster zRevRange(string $key, int $start, int $end, mixed $scores = null)
 * @method array|false|Redis|RedisCluster zRevRangeByLex(string $key, string $max, string $min, int $offset = -1, int $count = -1)
 * @method array|false|Redis|RedisCluster zRevRangeByScore(string $key, float|int|string $max, float|int|string $min, array $options = []) Get sorted set members by score range (reverse)
 * @method false|int|Redis|RedisCluster zRevRank(string $key, mixed $member)
 * @method false|float|Redis|RedisCluster zScore(string $key, mixed $member)
 * @method array|false|Redis|RedisCluster zdiff(array $keys, array|null $options = null)
 * @method false|int|Redis|RedisCluster zdiffstore(string $dst, array $keys)
 * @method array|false|Redis|RedisCluster zinter(array $keys, array|null $weights = null, array|null $options = null)
 * @method false|int|Redis|RedisCluster zintercard(array $keys, int $limit = -1)
 * @method array|false|Redis|RedisCluster zunion(array $keys, array|null $weights = null, array|null $options = null)
 */
abstract class RedisConnection extends BaseConnection implements NonCopyableContext
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * Top-level connection config keys that should be applied through setOption.
     */
    private const array CONNECTION_LEVEL_PHPREDIS_OPTIONS = [
        'read_timeout',
        'max_retries',
        'backoff_algorithm',
        'backoff_base',
        'backoff_cap',
    ];

    protected Redis|RedisCluster|null $connection = null;

    protected float $createdAt = 0.0;

    protected float $lifetimeExpiresAt = 0.0;

    protected bool $availableForReuse = false;

    protected ?Dispatcher $eventDispatcher = null;

    protected array $config;

    /**
     * Current redis database.
     */
    protected ?int $database = null;

    /**
     * Determine if the native connection is watching keys.
     */
    protected bool $watching = false;

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
        $this->config = $config;
    }

    /**
     * Pass other method calls down to the underlying client.
     * @param mixed $name
     * @param mixed $arguments
     */
    public function __call($name, $arguments)
    {
        try {
            if (static::hasMacro($name)) {
                return $this->macroCall($name, $arguments);
            }

            $name = strtolower($name);
            $result = $this->executeCommand($name, $arguments);
        } catch (RedisException|RedisClusterException $exception) {
            if ($this->shouldInvalidateAfter($exception)) {
                $this->markInvalid();
            }

            throw $exception;
        }

        if ($name === 'watch' && $result !== false) {
            $this->watching = true;
        } elseif (
            $name === 'exec'
            || ($name === 'unwatch' && $result !== false)
        ) {
            $this->watching = false;
        }

        if ($name === 'select' && $result === true && array_key_exists(0, $arguments)) {
            $this->database = (int) $arguments[0];
        }

        return $result;
    }

    /**
     * Execute a Redis command, applying transforms when enabled.
     *
     * @param array<int, mixed> $arguments
     */
    private function executeCommand(string $name, array $arguments): mixed
    {
        // REMOVED: RESET destroys authentication and database state owned by the pool.
        if ($name === 'reset') {
            throw new BadMethodCallException(
                'Cannot call reset() on a pooled Redis connection because it clears '
                . 'the authentication and selected database owned by the pool. '
                . 'For facade-managed connections, use Redis::discard(), Redis::unwatch(), '
                . 'or Redis::exec(). On a held connection, call discardTransaction(), '
                . 'unwatch(), or exec() on that same connection.'
            );
        }

        if (in_array($name, ['subscribe', 'psubscribe', 'ssubscribe'], true)) {
            return $this->callSubscribe($name, $arguments);
        }

        if (! $this->shouldTransform) {
            return $this->connection->{$name}(...$arguments);
        }

        // In MULTI/PIPELINE mode, only reshape arguments for phpredis —
        // skip return-value normalization to preserve queueing semantics.
        if ($this->isQueueingMode()) {
            $prepareMethod = 'prepare' . ucfirst($name);

            if (method_exists($this, $prepareMethod)) {
                [$method, $args] = $this->{$prepareMethod}(...$arguments);
                return $this->connection->{$method}(...$args);
            }

            return $this->connection->{$name}(...$arguments);
        }

        $callMethod = 'call' . ucfirst($name);

        if (method_exists($this, $callMethod)) {
            return $this->{$callMethod}(...$arguments);
        }

        return $this->connection->{$name}(...$arguments);
    }

    /**
     * Get the active connection.
     */
    public function getActiveConnection(): static
    {
        if ($this->check()) {
            $this->availableForReuse = false;

            return $this;
        }

        if (! $this->reconnect()) {
            throw new ConnectionException('Connection reconnect failed.');
        }

        return $this;
    }

    /**
     * Check if the connection is still valid.
     */
    public function check(): bool
    {
        if ($this->invalid) {
            return false;
        }

        if ($this->connection === null) {
            return false;
        }

        $now = hrtime(true) / 1e9;

        if ($this->availableForReuse) {
            // Mirrors Database\Pool\PooledConnection recycling logic. Keep in sync.
            if ($this->isLifetimeExpired($now)) {
                return false;
            }

            if ($now > $this->pool->getOption()->getMaxIdleTime() + max($this->lastReleaseTime, $this->lastUseTime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark the connection invalid before its next use.
     *
     * @internal
     */
    public function invalidate(): void
    {
        $this->markInvalid();
    }

    /**
     * Get the connection name.
     */
    public function getName(): string
    {
        return $this->pool->getName();
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
     */
    abstract public function reconnect(): bool;

    /**
     * Mark the underlying Redis client as freshly connected.
     */
    protected function markReconnected(): void
    {
        $now = hrtime(true) / 1e9;
        $this->lastUseTime = $now;
        $this->createdAt = $now;
        $this->lifetimeExpiresAt = PoolOption::jitteredLifetimeDeadline(
            $now,
            $this->pool->getOption()->getMaxLifetime()
        );
        $this->availableForReuse = false;
        $this->watching = false;
        $this->markValid();
    }

    /**
     * Set configured options on a Redis or RedisCluster client.
     */
    protected function setOptions(Redis|RedisCluster $redis): void
    {
        $options = $this->configuredPhpRedisOptions();

        foreach ($options as $name => $value) {
            if (is_string($name)) {
                $name = strtolower($name);

                if ($name === 'pack_ignore_numbers'
                    && (! $redis instanceof Redis || ! defined(Redis::class . '::OPT_PACK_IGNORE_NUMBERS'))) {
                    continue;
                }

                if ($name === 'backoff_algorithm') {
                    $value = $this->parseBackoffAlgorithm($value);
                }

                $name = $this->phpRedisOption($name);
            }

            $redis->setOption($name, $value);
        }
    }

    /**
     * Get the phpredis options configured on the connection.
     *
     * @return array<int|string, mixed>
     */
    protected function configuredPhpRedisOptions(): array
    {
        $connectionOptions = [];

        foreach (self::CONNECTION_LEVEL_PHPREDIS_OPTIONS as $key) {
            $value = $this->config[$key];

            if ($key === 'read_timeout' && empty($value)) {
                continue;
            }

            $connectionOptions[$key] = $value;
        }

        return array_replace($connectionOptions, $this->config['options']);
    }

    /**
     * Resolve a phpredis option name to its native option constant.
     */
    protected function phpRedisOption(string $name): int
    {
        return match ($name) {
            'serializer' => Redis::OPT_SERIALIZER,
            'prefix' => Redis::OPT_PREFIX,
            'read_timeout' => Redis::OPT_READ_TIMEOUT,
            'scan' => Redis::OPT_SCAN,
            'failover' => RedisCluster::OPT_SLAVE_FAILOVER,
            'tcp_keepalive' => Redis::OPT_TCP_KEEPALIVE,
            'compression' => Redis::OPT_COMPRESSION,
            'reply_literal' => Redis::OPT_REPLY_LITERAL,
            'compression_level' => Redis::OPT_COMPRESSION_LEVEL,
            'pack_ignore_numbers' => Redis::OPT_PACK_IGNORE_NUMBERS, // @phpstan-ignore classConstant.notFound (setOptions() skips this option when phpredis predates the constant)
            'max_retries' => Redis::OPT_MAX_RETRIES,
            'backoff_algorithm' => Redis::OPT_BACKOFF_ALGORITHM,
            'backoff_base' => Redis::OPT_BACKOFF_BASE,
            'backoff_cap' => Redis::OPT_BACKOFF_CAP,
            default => throw new InvalidRedisOptionException(sprintf('The redis option key `%s` is invalid.', $name)),
        };
    }

    /**
     * Parse a friendly phpredis backoff algorithm name.
     */
    protected function parseBackoffAlgorithm(mixed $algorithm): int
    {
        if (is_int($algorithm)) {
            return $algorithm;
        }

        return match ($algorithm) {
            'default' => Redis::BACKOFF_ALGORITHM_DEFAULT,
            'decorrelated_jitter' => Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
            'equal_jitter' => Redis::BACKOFF_ALGORITHM_EQUAL_JITTER,
            'exponential' => Redis::BACKOFF_ALGORITHM_EXPONENTIAL,
            'uniform' => Redis::BACKOFF_ALGORITHM_UNIFORM,
            'constant' => Redis::BACKOFF_ALGORITHM_CONSTANT,
            default => throw new InvalidRedisOptionException(
                sprintf('Algorithm [%s] is not a valid PhpRedis backoff algorithm.', (string) $algorithm)
            ),
        };
    }

    /**
     * Close the current connection.
     *
     * Calling the native phpredis close() releases the underlying socket
     * deterministically rather than relying on PHP refcount destruction -
     * connections trapped in pool/connection reference cycles would otherwise
     * keep their FDs open until the cycle collector runs.
     */
    public function close(): bool
    {
        try {
            $this->connection?->close();
        } catch (Throwable) {
            // Swallow errors from the underlying client (already-disconnected
            // socket, broken connection, RedisCluster variants without close, etc.).
            // PHP frees the client either way - we just want to null our reference.
        }

        $this->connection = null;
        $this->watching = false;

        return true;
    }

    /**
     * Check the Redis client for heartbeat health.
     */
    public function heartbeatCheck(float $timeout): bool
    {
        if ($this->invalid || ! ($this->connection instanceof Redis || $this->connection instanceof RedisCluster)) {
            return false;
        }

        $result = new Channel(1);

        try {
            $started = go(function () use ($result): void {
                try {
                    $result->push($this->pingForHeartbeat(), 0.0);
                } catch (CanceledException) {
                }
            });
        } catch (CoroutineCreateException) {
            return false;
        }

        if ($result->pop($timeout) !== true) {
            Coroutine::cancelById($started, throwException: true);

            return false;
        }

        $this->lastUseTime = hrtime(true) / 1e9;

        return true;
    }

    /**
     * Release the connection back to pool.
     */
    public function release(): void
    {
        $this->shouldTransform = false;

        try {
            $queueing = $this->isQueueingMode();
        } catch (Throwable $exception) {
            $this->markInvalid();

            try {
                $this->log('Release connection failed, caused by ' . $exception, LogLevel::CRITICAL);
            } catch (Throwable) {
                // Reporting must not prevent terminal ownership cleanup.
            }

            $this->database = null;
            $this->watching = false;
            $this->availableForReuse = true;
            parent::release();

            return;
        }

        if ($queueing || $this->watching) {
            try {
                $this->log(
                    $queueing
                        ? 'Discarding Redis connection left in MULTI or PIPELINE mode.'
                        : 'Discarding Redis connection left in WATCH state.',
                    LogLevel::CRITICAL
                );
            } catch (Throwable) {
                // Reporting must not prevent terminal ownership cleanup.
            }

            $this->database = null;
            $this->watching = false;
            $this->availableForReuse = false;
            $this->discard();

            return;
        }

        try {
            if ($this->connection instanceof Redis) {
                $defaultDatabase = $this->config['database'];

                if (! $this->connection->isConnected()) {
                    $this->markInvalid();
                } elseif ($this->connection->getDBNum() !== $defaultDatabase) {
                    if ($this->select($defaultDatabase) !== true) {
                        throw new ConnectionException(
                            "Failed to select Redis database [{$defaultDatabase}] on connection [{$this->getName()}]."
                        );
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->markInvalid();
            // A connected client would otherwise replay its rejected database during reconnect.
            $this->close();

            try {
                $this->log('Release connection failed, caused by ' . $exception, LogLevel::CRITICAL);
            } catch (Throwable) {
                // Reporting must not prevent terminal ownership cleanup.
            }
        } finally {
            $this->database = null;
            $this->watching = false;
            $this->availableForReuse = true;
            parent::release();
        }
    }

    /**
     * Execute the native Redis DISCARD command.
     *
     * Use this method on a held connection because discard() removes the
     * connection from its pool.
     */
    public function discardTransaction(): bool|Redis
    {
        $result = $this->connection->discard();

        if ($result !== false) {
            $this->watching = false;
        }

        return $result;
    }

    /**
     * Clear the tracked watch state after callback-form transaction completion.
     *
     * @internal
     */
    public function clearWatchState(): void
    {
        $this->watching = false;
    }

    /**
     * Determine if this connection has been idle long enough to be evicted.
     */
    public function isIdleExpired(?float $now = null): bool
    {
        if ($this->lastReleaseTime === 0.0) {
            return false;
        }

        // Heartbeat pings must not keep request-idle connections alive forever.
        return ($now ?? hrtime(true) / 1e9) > $this->pool->getOption()->getMaxIdleTime() + $this->lastReleaseTime;
    }

    /**
     * Get the connection generation creation time.
     */
    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    /**
     * Determine if this connection generation has reached its maximum lifetime.
     */
    public function isLifetimeExpired(?float $now = null): bool
    {
        if ($this->lifetimeExpiresAt <= 0) {
            return false;
        }

        return ($now ?? hrtime(true) / 1e9) >= $this->lifetimeExpiresAt;
    }

    /**
     * Determine whether a failed command left the connection unsafe to reuse.
     */
    protected function shouldInvalidateAfter(RedisException|RedisClusterException $exception): bool
    {
        if ($this->connection->getLastError() !== $exception->getMessage()) {
            return true;
        }

        if (! ($this->config['sentinel']['enabled'] ?? false)) {
            return false;
        }

        $errorCode = explode(' ', $exception->getMessage(), 2)[0];

        return in_array($errorCode, ['READONLY', 'MASTERDOWN'], true);
    }

    /**
     * Determine if the underlying Redis client is in pipeline/multi mode.
     *
     * Returns false by default. PhpRedisConnection overrides to check
     * the actual mode on the \Redis client.
     */
    protected function isQueueingMode(): bool
    {
        return false;
    }

    /**
     * Determine if the connection is to a Redis Cluster.
     */
    public function isCluster(): bool
    {
        return false;
    }

    /**
     * Determine if the given key contains a Redis Cluster hash tag.
     */
    public static function hasHashTag(string $key): bool
    {
        $open = strpos($key, '{');

        if ($open === false) {
            return false;
        }

        $close = strpos($key, '}', $open + 1);

        return $close !== false && $close - $open > 1;
    }

    /**
     * Ping Redis for heartbeat health without shadowing the public Redis PING command.
     */
    protected function pingForHeartbeat(): bool
    {
        try {
            if ($this->connection instanceof Redis) {
                return $this->connection->ping() !== false;
            }

            if ($this->connection instanceof RedisCluster) {
                $masters = $this->connection->_masters();

                if ($masters === []) {
                    return false;
                }

                foreach ($masters as $master) {
                    if ($this->connection->ping($master) === false) {
                        return false;
                    }
                }

                return true;
            }

            return false;
        } catch (Throwable $exception) {
            if ($exception instanceof CanceledException) {
                throw $exception;
            }

            return false;
        }
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
    protected function callGet(string $key): mixed
    {
        $result = $this->connection->get($key);

        return $result !== false ? $result : null;
    }

    /**
     * Get the values of all the given keys.
     */
    protected function callMget(array $keys): array|false
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->connection->mGet($keys);

        if ($values === false) {
            return false;
        }

        return array_map(
            static fn (mixed $value): mixed => $value !== false ? $value : null,
            $values,
        );
    }

    /**
     * Prepare arguments for the set command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareSet(mixed ...$arguments): array
    {
        [$key, $value] = $arguments;
        $expireResolution = $arguments[2] ?? null;
        $expireTTL = $arguments[3] ?? null;
        $flag = $arguments[4] ?? null;

        return ['set', [$key, $value, is_string($expireResolution) ? [$flag, $expireResolution => $expireTTL] : $expireResolution]];
    }

    /**
     * Set the string value in the argument as the value of the key.
     */
    protected function callSet(string $key, mixed $value, mixed $expireResolution = null, ?int $expireTTL = null, ?string $flag = null): mixed
    {
        [$method, $args] = $this->prepareSet($key, $value, $expireResolution, $expireTTL, $flag);

        // SET with GET returns the previous decoded value; false may mean there was no previous value.
        return $this->connection->{$method}(...$args);
    }

    /**
     * Set the given key if it doesn't exist.
     */
    protected function callSetnx(string $key, mixed $value): int
    {
        return (int) $this->connection->setNx($key, $value);
    }

    /**
     * Prepare arguments for the hmget command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareHmget(mixed ...$arguments): array
    {
        $key = array_shift($arguments);
        $dictionary = count($arguments) === 1 ? $arguments[0] : $arguments;

        return ['hMGet', [$key, $dictionary]];
    }

    /**
     * Get the value of the given hash fields.
     */
    protected function callHmget(string $key, mixed ...$dictionary): array|false
    {
        [$method, $args] = $this->prepareHmget($key, ...$dictionary);

        $values = $this->connection->{$method}(...$args);

        if ($values === false) {
            return false;
        }

        return array_values($values);
    }

    /**
     * Prepare arguments for the hmset command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareHmset(mixed ...$arguments): array
    {
        $key = array_shift($arguments);

        if (count($arguments) === 1) {
            $dictionary = $arguments[0];
        } else {
            $input = new Collection($arguments);

            $dictionary = $input->nth(2)->combine($input->nth(2, 1))->toArray();
        }

        return ['hMSet', [$key, $dictionary]];
    }

    /**
     * Set the given hash fields to their respective values.
     */
    protected function callHmset(string $key, mixed ...$dictionary): bool
    {
        [$method, $args] = $this->prepareHmset($key, ...$dictionary);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Set the given hash field if it doesn't exist.
     */
    protected function callHsetnx(string $hash, string $key, mixed $value): int
    {
        return (int) $this->connection->hSetNx($hash, $key, $value);
    }

    /**
     * Prepare arguments for the lrem command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareLrem(mixed ...$arguments): array
    {
        [$key, $count, $value] = $arguments;

        return ['lRem', [$key, $value, $count]];
    }

    /**
     * Removes the first count occurrences of the value element from the list.
     */
    protected function callLrem(string $key, int $count, mixed $value): false|int
    {
        [$method, $args] = $this->prepareLrem($key, $count, $value);

        return $this->connection->{$method}(...$args);
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
     * Prepare arguments for the zadd command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareZadd(mixed ...$arguments): array
    {
        $key = array_shift($arguments);
        $dictionary = $arguments;

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

        return ['zAdd', [$key, $options, ...array_values($dictionary)]];
    }

    /**
     * Add one or more members to a sorted set or update its score if it already exists.
     */
    protected function callZadd(string $key, mixed ...$dictionary): false|float|int
    {
        [$method, $args] = $this->prepareZadd($key, ...$dictionary);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Prepare arguments for the zrangebyscore command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareZrangebyscore(mixed ...$arguments): array
    {
        [$key, $min, $max] = $arguments;
        $options = $arguments[3] ?? [];

        if (isset($options['limit']) && ! array_is_list($options['limit'])) {
            $options['limit'] = [
                $options['limit']['offset'],
                $options['limit']['count'],
            ];
        }

        return ['zRangeByScore', [$key, $min, $max, $options]];
    }

    /**
     * Return elements with score between $min and $max.
     */
    protected function callZrangebyscore(string $key, float|int|string $min, float|int|string $max, array $options = []): array|false
    {
        [$method, $args] = $this->prepareZrangebyscore($key, $min, $max, $options);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Prepare arguments for the zrevrangebyscore command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareZrevrangebyscore(mixed ...$arguments): array
    {
        [$key, $max, $min] = $arguments;
        $options = $arguments[3] ?? [];

        if (isset($options['limit']) && ! array_is_list($options['limit'])) {
            $options['limit'] = [
                $options['limit']['offset'],
                $options['limit']['count'],
            ];
        }

        return ['zRevRangeByScore', [$key, $max, $min, $options]];
    }

    /**
     * Return elements with score between $max and $min in reverse order.
     */
    protected function callZrevrangebyscore(string $key, float|int|string $max, float|int|string $min, array $options = []): array|false
    {
        [$method, $args] = $this->prepareZrevrangebyscore($key, $max, $min, $options);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Prepare arguments for the zinterstore command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareZinterstore(mixed ...$arguments): array
    {
        [$output, $keys] = $arguments;
        $options = $arguments[2] ?? [];

        return ['zinterstore', [$output, $keys, $options['weights'] ?? null, $options['aggregate'] ?? 'sum']];
    }

    /**
     * Find the intersection between sets and store in a new set.
     */
    protected function callZinterstore(string $output, array $keys, array $options = []): false|int
    {
        [$method, $args] = $this->prepareZinterstore($output, $keys, $options);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Prepare arguments for the zunionstore command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareZunionstore(mixed ...$arguments): array
    {
        [$output, $keys] = $arguments;
        $options = $arguments[2] ?? [];

        return ['zunionstore', [$output, $keys, $options['weights'] ?? null, $options['aggregate'] ?? 'sum']];
    }

    /**
     * Find the union between sets and store in a new set.
     */
    protected function callZunionstore(string $output, array $keys, array $options = []): false|int
    {
        [$method, $args] = $this->prepareZunionstore($output, $keys, $options);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Get the Redis scan options.
     */
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
     * Prepare arguments for the eval command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareEval(mixed ...$arguments): array
    {
        $script = $arguments[0];
        $numberOfKeys = $arguments[1];
        $args = array_slice($arguments, 2);

        return ['eval', [$script, $args, $numberOfKeys]];
    }

    /**
     * Evaluate a script and return its result.
     */
    protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
    {
        [$method, $args] = $this->prepareEval($script, $numberOfKeys, ...$arguments);

        return $this->normalizeNullReplies($this->connection->{$method}(...$args));
    }

    /**
     * Prepare arguments for the evalsha command.
     *
     * Falls back to eval in MULTI/PIPELINE mode because script('load')
     * cannot execute synchronously while the connection is queueing.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareEvalsha(mixed ...$arguments): array
    {
        $script = $arguments[0];
        $numkeys = $arguments[1];
        $args = array_slice($arguments, 2);

        return ['eval', [$script, $args, $numkeys]];
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
     * Prepare arguments for the executeRaw command.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareExecuteRaw(mixed ...$arguments): array
    {
        return ['rawCommand', $arguments[0]];
    }

    /**
     * Execute a raw command.
     */
    protected function callExecuteRaw(array $parameters): mixed
    {
        [$method, $args] = $this->prepareExecuteRaw($parameters);

        return $this->connection->{$method}(...$args);
    }

    /**
     * Reject subscriptions on raw pooled connections.
     *
     * Pub/sub requires a dedicated, long-lived connection that is incompatible
     * with connection pooling. Use the coroutine-native subscriber instead:
     *
     *     Redis::subscribe($channels, $callback);
     *     Redis::psubscribe($channels, $callback);
     *     $subscriber = Redis::subscriber();
     *
     * @throws BadMethodCallException
     */
    protected function callSubscribe(string $name, array $arguments): never
    {
        throw new BadMethodCallException(
            "Cannot call {$name}() on a pooled RedisConnection. "
            . 'Use Redis::subscribe(), Redis::psubscribe(), or Redis::subscriber() for ordinary Pub/Sub.'
        );
    }

    /**
     * Determine if a custom serializer is configured on the connection.
     */
    public function serialized(): bool
    {
        return $this->connection->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE;
    }

    /**
     * Determine if compression is configured on the connection.
     */
    public function compressed(): bool
    {
        return $this->connection->getOption(Redis::OPT_COMPRESSION) !== Redis::COMPRESSION_NONE;
    }

    /**
     * Execute the given callback without serialization or compression.
     *
     * Temporarily disables phpredis serialization and compression on the raw
     * connection for operations that require raw integer values (e.g., rate
     * limiter counters), then restores the original settings.
     */
    public function withoutSerializationOrCompression(callable $callback): mixed
    {
        $oldSerializer = null;

        if ($this->serialized()) {
            $oldSerializer = $this->connection->getOption(Redis::OPT_SERIALIZER);
            $this->connection->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        }

        $oldCompressor = null;

        if ($this->compressed()) {
            $oldCompressor = $this->connection->getOption(Redis::OPT_COMPRESSION);
            $this->connection->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);
        }

        try {
            return $callback();
        } finally {
            if ($oldSerializer !== null) {
                $this->connection->setOption(Redis::OPT_SERIALIZER, $oldSerializer);
            }

            if ($oldCompressor !== null) {
                $this->connection->setOption(Redis::OPT_COMPRESSION, $oldCompressor);
            }
        }
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
     * Execute a Lua script using evalSha with automatic fallback to eval.
     *
     * Redis caches compiled Lua scripts by SHA1 hash. This method tries evalSha
     * first (uses cached compiled script), and falls back to eval if the script
     * isn't cached yet (NOSCRIPT error).
     *
     * Unlike naive implementations that treat any `false` return as NOSCRIPT,
     * this method wraps script and data errors returned by phpredis as `false`
     * while native server, cluster, authentication, and transport exceptions
     * propagate unchanged.
     *
     * @param string $script The Lua script to execute
     * @param array<string> $keys Redis keys (passed as KEYS[] in Lua)
     * @param array<mixed> $args Additional arguments (passed as ARGV[] in Lua)
     * @return mixed The script's return value
     *
     * @throws LuaScriptException If phpredis returns a non-NOSCRIPT script or data error
     * @throws RedisException If Redis rejects execution or the connection fails
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
                        throw $this->scriptException($evalError);
                    }
                    // If no error, script legitimately returned nil (which becomes false)
                }
            } elseif ($error !== null) {
                // Some other error (syntax, OOM, WRONGTYPE, etc.)
                throw $this->scriptException($error);
            }
            // If $error is null and $result is false, the script legitimately returned false
        }

        return $this->normalizeNullReplies($result);
    }

    /**
     * Create an exception for a Lua script error.
     */
    protected function scriptException(string $error): Throwable
    {
        return new LuaScriptException('Lua script execution failed: ' . $error);
    }

    /**
     * Normalize topology-specific null replies.
     */
    protected function normalizeNullReplies(mixed $result): mixed
    {
        return $result;
    }

    /**
     * Safely scan the Redis keyspace for keys matching a pattern.
     *
     * This method handles the phpredis OPT_PREFIX complexity correctly:
     * - Automatically prepends OPT_PREFIX to the scan pattern
     * - Strips OPT_PREFIX from returned keys so they work with other commands
     *
     * The connection must be held with transform disabled so SCAN retains its
     * native phpredis cursor and result shape.
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
     * Use this inside withConnection(..., transform: false) when doing multiple
     * operations on the same connection. No connection lifecycle overhead since
     * you're operating on an existing connection.
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
