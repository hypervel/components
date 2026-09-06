<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static mixed command(string $method, array $parameters = [])
 * @method static \Hypervel\Redis\RedisProxy connection(\UnitEnum|string|null $name = null)
 * @method static array<string, \Hypervel\Redis\RedisProxy> connections()
 * @method static void disableEvents()
 * @method static void enableEvents()
 * @method static \Hypervel\Redis\Limiters\ConcurrencyLimiterBuilder funnel(string $name)
 * @method static void listen(\Closure $callback)
 * @method static void listenForFailures(\Closure $callback)
 * @method static void psubscribe(array|string $channels, \Closure $callback)
 * @method static void purge(\UnitEnum|string|null $name = null)
 * @method static void subscribe(array|string $channels, \Closure $callback)
 * @method static \Hypervel\Redis\Limiters\DurationLimiterBuilder throttle(string $name)
 * @method static bool|\Redis discard()
 * @method static int flushByPattern(string $pattern)
 * @method static void flushMacros()
 * @method static string getName()
 * @method static bool hasMacro(string $name)
 * @method static void hScan(mixed $key, mixed $cursor, mixed ...$arguments)
 * @method static bool isCluster()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static ($callback is null ? \Redis : array<int, mixed>|false) pipeline(callable|null $callback = null)
 * @method static void scan(mixed $cursor, mixed ...$arguments)
 * @method static void sScan(mixed $key, mixed $cursor, mixed ...$arguments)
 * @method static \Hypervel\Redis\Subscriber\Subscriber subscriber()
 * @method static ($callback is null ? \Redis|\RedisCluster : array<int, mixed>|false) transaction(callable|null $callback = null)
 * @method static mixed withConnection(callable $callback, bool $transform = true)
 * @method static mixed withoutSerializationOrCompression(callable $callback)
 * @method static mixed withPinnedConnection(callable $callback)
 * @method static void zScan(mixed $key, mixed $cursor, mixed ...$arguments)
 * @method static string _digest(mixed $value)
 * @method static string _pack(mixed $value)
 * @method static string _serialize(mixed $value) Serialize a value using configured serializer
 * @method static mixed _unpack(string $value)
 * @method static mixed acl(string $subcmd, string ...$args)
 * @method static false|int|\Redis|\RedisCluster append(string $key, mixed $value)
 * @method static bool|\Redis|\RedisCluster bgrewriteaof()
 * @method static bool|\Redis|\RedisCluster bgSave()
 * @method static false|int|\Redis|\RedisCluster bitcount(string $key, int $start = 0, int $end = -1, bool $bybit = false)
 * @method static false|int|\Redis|\RedisCluster bitop(string $operation, string $deskey, string $srckey, string ...$other_keys)
 * @method static false|int|\Redis|\RedisCluster bitpos(string $key, bool $bit, int $start = 0, int $end = -1, bool $bybit = false)
 * @method static false|\Redis|\RedisCluster|string blmove(string $src, string $dst, string $wherefrom, string $whereto, float $timeout)
 * @method static null|array|false|\Redis|\RedisCluster blmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method static null|array|false|\Redis|\RedisCluster blpop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args) Blocking left pop from list
 * @method static null|array|false|\Redis|\RedisCluster brpop(array|string $key_or_keys, float|int|string $timeout_or_key, mixed ...$extra_args) Blocking right pop from list
 * @method static false|\Redis|\RedisCluster|string brpoplpush(string $src, string $dst, float|int $timeout)
 * @method static null|array|false|\Redis|\RedisCluster bzmpop(float $timeout, array $keys, string $from, int $count = 1)
 * @method static array|false|\Redis|\RedisCluster bzPopMax(array|string $key, int|string $timeout_or_key, mixed ...$extra_args)
 * @method static array|false|\Redis|\RedisCluster bzPopMin(array|string $key, int|string $timeout_or_key, mixed ...$extra_args)
 * @method static bool clearLastError()
 * @method static void clearTransferredBytes()
 * @method static bool compressed()
 * @method static mixed config(string $operation, array|string|null $key_or_settings = null, string|null $value = null)
 * @method static bool|\Redis|\RedisCluster copy(string $src, string $dst, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster dbSize()
 * @method static \Redis|string debug(string $key)
 * @method static false|int|\Redis|\RedisCluster decr(string $key, int $by = 1)
 * @method static false|int|\Redis|\RedisCluster decrBy(string $key, int $value)
 * @method static false|int|\Redis|\RedisCluster del(array|string $key, string ...$other_keys)
 * @method static false|int|\Redis|\RedisCluster delex(string $key, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster delifeq(string $key, mixed $value)
 * @method static false|\Redis|\RedisCluster|string digest(string $key)
 * @method static false|\Redis|\RedisCluster|string dump(string $key)
 * @method static false|\Redis|\RedisCluster|string echo(string $str)
 * @method static mixed eval(string $script, int $numberOfKeys, mixed ...$arguments) Evaluate Lua script
 * @method static mixed eval_ro(string $script_sha, array $args = [], int $num_keys = 0)
 * @method static mixed evalsha(string $script, int $numkeys, mixed ...$arguments) Evaluate Lua script by SHA1
 * @method static mixed evalsha_ro(string $sha1, array $args = [], int $num_keys = 0)
 * @method static mixed evalWithShaCache(string $script, array<string> $keys = [], array<mixed> $args = [])
 * @method static array|false|\Redis exec()
 * @method static mixed executeRaw(array $parameters) Execute raw Redis command
 * @method static bool|int|\Redis|\RedisCluster exists(mixed $key, mixed ...$other_keys)
 * @method static bool|\Redis|\RedisCluster expire(string $key, int $timeout, string|null $mode = null)
 * @method static bool|\Redis|\RedisCluster expireAt(string $key, int $timestamp, string|null $mode = null)
 * @method static false|int|\Redis|\RedisCluster expiretime(string $key)
 * @method static bool|\Redis failover(array|null $to = null, bool $abort = false, int $timeout = 0)
 * @method static mixed fcall(string $fn, array $keys = [], array $args = [])
 * @method static mixed fcall_ro(string $fn, array $keys = [], array $args = [])
 * @method static bool|\Redis|\RedisCluster flushAll(bool|null $sync = null)
 * @method static mixed flushdb(mixed ...$arguments) Flush database
 * @method static array|bool|\Redis|string function(string $operation, mixed ...$args)
 * @method static false|int|\Redis|\RedisCluster geoadd(string $key, float $lng, float $lat, string $member, mixed ...$other_triples_and_options)
 * @method static false|float|\Redis|\RedisCluster geodist(string $key, string $src, string $dst, string|null $unit = null)
 * @method static array|false|\Redis|\RedisCluster geohash(string $key, string $member, string ...$other_members)
 * @method static array|false|\Redis|\RedisCluster geopos(string $key, string $member, string ...$other_members)
 * @method static mixed georadius(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method static mixed georadius_ro(string $key, float $lng, float $lat, float $radius, string $unit, array $options = [])
 * @method static mixed georadiusbymember(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method static mixed georadiusbymember_ro(string $key, string $member, float $radius, string $unit, array $options = [])
 * @method static array geosearch(string $key, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method static array|false|int|\Redis|\RedisCluster geosearchstore(string $dst, string $src, array|string $position, array|int|float $shape, string $unit, array $options = [])
 * @method static mixed get(string $key) Get the value of a key
 * @method static mixed getAuth()
 * @method static false|int|\Redis|\RedisCluster getBit(string $key, int $idx)
 * @method static int getDBNum()
 * @method static bool|\Redis|\RedisCluster|string getDel(string $key)
 * @method static \Hypervel\Contracts\Events\Dispatcher|null getEventDispatcher()
 * @method static bool|\Redis|\RedisCluster|string getEx(string $key, array $options = [])
 * @method static string getHost()
 * @method static null|string getLastError()
 * @method static int getMode()
 * @method static mixed getOption(int $option)
 * @method static null|string getPersistentID()
 * @method static int getPort()
 * @method static false|\Redis|\RedisCluster|string getRange(string $key, int $start, int $end)
 * @method static float getReadTimeout()
 * @method static false|\Redis|\RedisCluster|string getset(string $key, mixed $value)
 * @method static false|float getTimeout()
 * @method static array getTransferredBytes()
 * @method static array|false|\Redis|\RedisCluster getWithMeta(string $key)
 * @method static bool hasHashTag(string $key)
 * @method static false|int|\Redis|\RedisCluster hdel(string $key, string $field, string ...$other_fields) Delete hash fields
 * @method static bool|\Redis|\RedisCluster hExists(string $key, string $field)
 * @method static array|false|\Redis|\RedisCluster hexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method static array|false|\Redis|\RedisCluster hexpireat(string $key, int $time, array $fields, string|null $mode = null)
 * @method static array|false|\Redis|\RedisCluster hexpiretime(string $key, array $fields)
 * @method static mixed hget(string $key, string $member) Get hash field value
 * @method static array|false|\Redis|\RedisCluster hGetAll(string $key)
 * @method static array|false|\Redis|\RedisCluster hgetdel(string $key, array $fields)
 * @method static array|false|\Redis|\RedisCluster hgetex(string $key, array $fields, string|array|null $expiry = null)
 * @method static mixed hGetWithMeta(string $key, string $member)
 * @method static false|int|\Redis|\RedisCluster hIncrBy(string $key, string $field, int $value)
 * @method static false|float|\Redis|\RedisCluster hIncrByFloat(string $key, string $field, float $value)
 * @method static array|false|\Redis|\RedisCluster hkeys(string $key) Get all hash field names
 * @method static false|int|\Redis|\RedisCluster hlen(string $key) Get number of hash fields
 * @method static array|false|\Redis|\RedisCluster hmget(string $key, array $fields) Get hash field values
 * @method static bool|\Redis|\RedisCluster hmset(string $key, array $fieldValues) Set hash field values
 * @method static array|false|\Redis|\RedisCluster hpersist(string $key, array $fields)
 * @method static array|false|\Redis|\RedisCluster hpexpire(string $key, int $ttl, array $fields, string|null $mode = null)
 * @method static array|false|\Redis|\RedisCluster hpexpireat(string $key, int $mstime, array $fields, string|null $mode = null)
 * @method static array|false|\Redis|\RedisCluster hpexpiretime(string $key, array $fields)
 * @method static array|false|\Redis|\RedisCluster hpttl(string $key, array $fields)
 * @method static array|false|\Redis|\RedisCluster|string hRandField(string $key, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster hset(string $key, mixed ...$fields_and_vals) Set hash field values
 * @method static false|int|\Redis|\RedisCluster hsetex(string $key, array $fields, array|null $expiry = null)
 * @method static bool|int|\Redis|\RedisCluster hsetnx(string $hash, string $key, mixed $value) Set hash field if not exists
 * @method static false|int|\Redis|\RedisCluster hStrLen(string $key, string $field)
 * @method static array|false|\Redis|\RedisCluster httl(string $key, array $fields)
 * @method static array|false|\Redis|\RedisCluster hVals(string $key)
 * @method static false|int|\Redis|\RedisCluster incr(string $key, int $by = 1)
 * @method static false|int|\Redis|\RedisCluster incrBy(string $key, int $value)
 * @method static false|float|\Redis|\RedisCluster incrByFloat(string $key, float $value)
 * @method static array|false|\Redis|\RedisCluster info(string ...$sections)
 * @method static bool isConnected()
 * @method static array|false|\Redis|\RedisCluster keys(string $pattern)
 * @method static int lastSave()
 * @method static array|false|int|\Redis|\RedisCluster|string lcs(string $key1, string $key2, array|null $options = null)
 * @method static mixed lindex(string $key, int $index)
 * @method static false|int|\Redis|\RedisCluster lInsert(string $key, string $pos, mixed $pivot, mixed $value)
 * @method static false|int|\Redis|\RedisCluster llen(string $key) Get list length
 * @method static false|\Redis|\RedisCluster|string lMove(string $src, string $dst, string $wherefrom, string $whereto)
 * @method static null|array|false|\Redis|\RedisCluster lmpop(array $keys, string $from, int $count = 1)
 * @method static array|bool|\Redis|\RedisCluster|string lPop(string $key, int $count = 0)
 * @method static null|array|bool|int|\Redis|\RedisCluster lPos(string $key, mixed $value, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster lPush(string $key, mixed ...$elements)
 * @method static false|int|\Redis|\RedisCluster lPushx(string $key, mixed $value)
 * @method static array|false|\Redis|\RedisCluster lrange(string $key, int $start, int $end)
 * @method static false|int lrem(string $key, int $count, mixed $value) Remove list elements
 * @method static bool|\Redis|\RedisCluster lSet(string $key, int $index, mixed $value)
 * @method static bool|\Redis|\RedisCluster ltrim(string $key, int $start, int $end)
 * @method static array|false|\Redis|\RedisCluster mget(array $keys) Get the values of multiple keys
 * @method static bool|\Redis migrate(string $host, int $port, array|string $key, int $dstdb, int $timeout, bool $copy = false, bool $replace = false, mixed $credentials = null)
 * @method static bool|\Redis move(string $key, int $index)
 * @method static bool|\Redis|\RedisCluster mset(array $key_values)
 * @method static false|int|\Redis|\RedisCluster msetex(array $key_values, int|float|array|null $expiry = null)
 * @method static bool|\Redis|\RedisCluster msetnx(array $key_values)
 * @method static bool|\Redis|\RedisCluster multi(int $value = 1)
 * @method static false|int|\Redis|\RedisCluster|string object(string $subcommand, string $key)
 * @method static array<int|string, string> pack(array<int|string, mixed> $values)
 * @method static bool|\Redis|\RedisCluster persist(string $key)
 * @method static bool pexpire(string $key, int $timeout, string|null $mode = null)
 * @method static bool|\Redis|\RedisCluster pexpireAt(string $key, int $timestamp, string|null $mode = null)
 * @method static false|int|\Redis|\RedisCluster pexpiretime(string $key)
 * @method static int|\Redis|\RedisCluster pfadd(string $key, array $elements)
 * @method static false|int|\Redis|\RedisCluster pfcount(array|string $key_or_keys)
 * @method static bool|\Redis|\RedisCluster pfmerge(string $dst, array $srckeys)
 * @method static bool|\Redis|\RedisCluster|string ping(string|null $message = null)
 * @method static bool|\Redis|\RedisCluster psetex(string $key, int $expire, mixed $value)
 * @method static false|int|\Redis|\RedisCluster pttl(string $key)
 * @method static false|int|\Redis|\RedisCluster publish(string $channel, string $message)
 * @method static mixed pubsub(string $command, mixed $arg = null)
 * @method static array|bool|\Redis punsubscribe(array $patterns)
 * @method static false|\Redis|\RedisCluster|string randomKey()
 * @method static mixed rawcommand(string $command, mixed ...$args)
 * @method static bool|\Redis|\RedisCluster rename(string $old_name, string $new_name)
 * @method static bool|\Redis|\RedisCluster renameNx(string $key_src, string $key_dst)
 * @method static bool|\Redis replicaof(string|null $host = null, int $port = 6379)
 * @method static bool|\Redis|\RedisCluster restore(string $key, int $ttl, string $value, array|null $options = null)
 * @method static mixed role()
 * @method static array|bool|\Redis|\RedisCluster|string rPop(string $key, int $count = 0)
 * @method static false|\Redis|\RedisCluster|string rpoplpush(string $srckey, string $dstkey)
 * @method static false|int|\Redis|\RedisCluster rPush(string $key, mixed ...$elements)
 * @method static false|int|\Redis|\RedisCluster rPushx(string $key, mixed $value)
 * @method static false|int|\Redis|\RedisCluster sAdd(string $key, mixed $value, mixed ...$other_values)
 * @method static int sAddArray(string $key, array $values)
 * @method static bool|\Redis|\RedisCluster save()
 * @method static false|int|\Redis|\RedisCluster scard(string $key)
 * @method static mixed script(string $command, mixed ...$args)
 * @method static array|false|\Redis|\RedisCluster sDiff(string $key, string ...$other_keys)
 * @method static false|int|\Redis|\RedisCluster sDiffStore(string $dst, string $key, string ...$other_keys)
 * @method static bool|\Redis select(int $db)
 * @method static bool serialized()
 * @method static false|string serverName()
 * @method static false|string serverVersion()
 * @method static mixed set(string $key, mixed $value, mixed $expireResolution = null, int|null $expireTTL = null, string|null $flag = null) Set the value of a key
 * @method static false|int|\Redis|\RedisCluster setBit(string $key, int $idx, bool $value)
 * @method static bool|\Redis|\RedisCluster setex(string $key, int $expire, mixed $value)
 * @method static bool|int|\Redis|\RedisCluster setnx(string $key, mixed $value) Set key if not exists
 * @method static false|int|\Redis|\RedisCluster setRange(string $key, int $index, string $value)
 * @method static array|false|\Redis|\RedisCluster sInter(array|string $key, string ...$other_keys)
 * @method static false|int|\Redis|\RedisCluster sintercard(array $keys, int $limit = -1)
 * @method static false|int|\Redis|\RedisCluster sInterStore(array|string $key, string ...$other_keys)
 * @method static bool|\Redis|\RedisCluster sismember(string $key, mixed $value)
 * @method static mixed slowlog(string $operation, int $length = 0)
 * @method static array|false|\Redis|\RedisCluster smembers(string $key) Get all set members
 * @method static array|false|\Redis|\RedisCluster sMisMember(string $key, string $member, string ...$other_members)
 * @method static bool|\Redis|\RedisCluster sMove(string $src, string $dst, mixed $value)
 * @method static mixed sort(string $key, array|null $options = null)
 * @method static mixed sort_ro(string $key, array|null $options = null)
 * @method static mixed spop(string $key, int $count = 0) Remove and return random set member
 * @method static mixed sRandMember(string $key, int $count = 0)
 * @method static false|int|\Redis|\RedisCluster sRem(string $key, mixed $value, mixed ...$other_values) Remove members from set
 * @method static false|int|\Redis|\RedisCluster strlen(string $key)
 * @method static array|false|\Redis|\RedisCluster sUnion(string $key, string ...$other_keys)
 * @method static false|int|\Redis|\RedisCluster sUnionStore(string $dst, string $key, string ...$other_keys)
 * @method static array|bool|\Redis sunsubscribe(array $channels)
 * @method static bool|\Redis swapdb(int $src, int $dst)
 * @method static array|\Redis|\RedisCluster time()
 * @method static false|int|\Redis|\RedisCluster touch(array|string $key_or_array, string ...$more_keys)
 * @method static false|int|\Redis|\RedisCluster ttl(string $key)
 * @method static false|int|\Redis|\RedisCluster type(string $key)
 * @method static false|int|\Redis|\RedisCluster unlink(array|string $key, string ...$other_keys)
 * @method static array|bool|\Redis unsubscribe(array $channels)
 * @method static null|bool|\Redis unwatch()
 * @method static false|int|\Redis|\RedisCluster vadd(string $key, array $values, mixed $element, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster vcard(string $key)
 * @method static false|int|\Redis|\RedisCluster vdim(string $key)
 * @method static array|false|\Redis|\RedisCluster vemb(string $key, mixed $member, bool $raw = false)
 * @method static array|false|\Redis|\RedisCluster|string vgetattr(string $key, mixed $member, bool $decode = true)
 * @method static array|false|\Redis|\RedisCluster vinfo(string $key)
 * @method static bool|\Redis|\RedisCluster vismember(string $key, mixed $member)
 * @method static array|false|\Redis|\RedisCluster vlinks(string $key, mixed $member, bool $withscores = false)
 * @method static array|false|\Redis|\RedisCluster|string vrandmember(string $key, int $count = 0)
 * @method static array|false|\Redis|\RedisCluster vrange(string $key, string $min, string $max, int $count = -1)
 * @method static false|int|\Redis|\RedisCluster vrem(string $key, mixed $member)
 * @method static false|int|\Redis|\RedisCluster vsetattr(string $key, mixed $member, array|string $attributes)
 * @method static array|false|\Redis|\RedisCluster vsim(string $key, mixed $member, array|null $options = null)
 * @method static false|int wait(int $numreplicas, int $timeout)
 * @method static array|false|\Redis|\RedisCluster waitaof(int $numlocal, int $numreplicas, int $timeout)
 * @method static bool|\Redis|\RedisCluster watch(array|string $key, string ...$other_keys)
 * @method static false|int xack(string $key, string $group, array $ids)
 * @method static false|\Redis|\RedisCluster|string xadd(string $key, string $id, array $values, int $maxlen = 0, bool $approx = false, bool $nomkstream = false)
 * @method static array|bool|\Redis|\RedisCluster xautoclaim(string $key, string $group, string $consumer, int $min_idle, string $start, int $count = -1, bool $justid = false)
 * @method static array|bool|\Redis|\RedisCluster xclaim(string $key, string $group, string $consumer, int $min_idle, array $ids, array $options)
 * @method static false|int|\Redis|\RedisCluster xdel(string $key, array $ids)
 * @method static array|false|\Redis|\RedisCluster xdelex(string $key, array $ids, string|null $mode = null)
 * @method static mixed xgroup(string $operation, string|null $key = null, string|null $group = null, string|null $id_or_consumer = null, bool $mkstream = false, int $entries_read = -2)
 * @method static mixed xinfo(string $operation, string|null $arg1 = null, string|null $arg2 = null, int $count = -1)
 * @method static false|int|\Redis|\RedisCluster xlen(string $key)
 * @method static array|false|\Redis|\RedisCluster xpending(string $key, string $group, string|null $start = null, string|null $end = null, int $count = -1, string|null $consumer = null)
 * @method static array|bool|\Redis|\RedisCluster xrange(string $key, string $start, string $end, int $count = -1)
 * @method static array|bool|\Redis|\RedisCluster xread(array $streams, int $count = -1, int $block = -1)
 * @method static array|bool|\Redis|\RedisCluster xreadgroup(string $group, string $consumer, array $streams, int $count = 1, int $block = 1)
 * @method static array|bool|\Redis|\RedisCluster xrevrange(string $key, string $end, string $start, int $count = -1)
 * @method static false|int|\Redis|\RedisCluster xtrim(string $key, string $threshold, bool $approx = false, bool $minid = false, int $limit = -1)
 * @method static false|float|int|\Redis|\RedisCluster zadd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems) Add members to sorted set
 * @method static false|int|\Redis|\RedisCluster zcard(string $key) Get sorted set cardinality
 * @method static false|int|\Redis|\RedisCluster zcount(string $key, int|string $start, int|string $end) Count sorted set members by score range
 * @method static array|false|\Redis|\RedisCluster zdiff(array $keys, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster zdiffstore(string $dst, array $keys)
 * @method static false|float|\Redis|\RedisCluster zIncrBy(string $key, float $value, mixed $member)
 * @method static array|false|\Redis|\RedisCluster zinter(array $keys, array|null $weights = null, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster zintercard(array $keys, int $limit = -1)
 * @method static false|int|\Redis|\RedisCluster zinterstore(string $output, array $keys, array $options = []) Intersect sorted sets
 * @method static false|int|\Redis|\RedisCluster zLexCount(string $key, string $min, string $max)
 * @method static null|array|false|\Redis|\RedisCluster zmpop(array $keys, string $from, int $count = 1)
 * @method static array|false|\Redis|\RedisCluster zMscore(string $key, mixed $member, mixed ...$other_members)
 * @method static array|false|\Redis|\RedisCluster zPopMax(string $key, int|null $count = null)
 * @method static array|false|\Redis|\RedisCluster zPopMin(string $key, int|null $count = null)
 * @method static array|\Redis|\RedisCluster|string zRandMember(string $key, array|null $options = null)
 * @method static array|false|\Redis|\RedisCluster zRange(string $key, string|int $start, string|int $end, array|bool|null $options = null)
 * @method static array|false|\Redis|\RedisCluster zRangeByLex(string $key, string $min, string $max, int $offset = -1, int $count = -1)
 * @method static array|false|\Redis|\RedisCluster zrangebyscore(string $key, float|int|string $min, float|int|string $max, array $options = []) Get sorted set members by score range
 * @method static false|int|\Redis|\RedisCluster zrangestore(string $dstkey, string $srckey, string $start, string $end, array|bool|null $options = null)
 * @method static false|int|\Redis|\RedisCluster zRank(string $key, mixed $member)
 * @method static false|int|\Redis|\RedisCluster zrem(mixed $key, mixed $member, mixed ...$other_members) Remove sorted set members
 * @method static false|int|\Redis|\RedisCluster zRemRangeByLex(string $key, string $min, string $max)
 * @method static false|int|\Redis|\RedisCluster zRemRangeByRank(string $key, int $start, int $end)
 * @method static false|int|\Redis|\RedisCluster zRemRangeByScore(string $key, string $start, string $end)
 * @method static array|false|\Redis|\RedisCluster zRevRange(string $key, int $start, int $end, mixed $scores = null)
 * @method static array|false|\Redis|\RedisCluster zRevRangeByLex(string $key, string $max, string $min, int $offset = -1, int $count = -1)
 * @method static array|false|\Redis|\RedisCluster zrevrangebyscore(string $key, float|int|string $max, float|int|string $min, array $options = []) Get sorted set members by score range (reverse)
 * @method static false|int|\Redis|\RedisCluster zRevRank(string $key, mixed $member)
 * @method static false|float|\Redis|\RedisCluster zScore(string $key, mixed $member)
 * @method static array|false|\Redis|\RedisCluster zunion(array $keys, array|null $weights = null, array|null $options = null)
 * @method static false|int|\Redis|\RedisCluster zunionstore(string $output, array $keys, array $options = []) Union sorted sets
 *
 * @see \Hypervel\Redis\RedisManager
 */
class Redis extends Facade
{
    /**
     * Get methods that should be excluded from the generated facade docblock.
     *
     * Excludes connection-bound methods, unavailable pooled commands, and
     * internal trait aliases.
     *
     * @return array<int, string>
     */
    protected static function ignoredFacadeDocumenterMethods(): array
    {
        return [
            'auth',
            'check',
            'client',
            'clearWatchState',
            'close',
            'connect',
            'discardTransaction',
            'getActiveConnection',
            'getConnection',
            'getCreatedAt',
            'getLastReleaseTime',
            'getLastUseTime',
            'getShouldTransform',
            'heartbeatCheck',
            'invalidate',
            'isIdleExpired',
            'isLifetimeExpired',
            'macroCall',
            'masters',
            'pconnect',
            'reconnect',
            'release',
            'reset',
            'safeScan',
            'setOption',
            'shouldTransform',
            'ssubscribe',
            'withoutScanPrefix',
        ];
    }

    protected static function getFacadeAccessor(): string
    {
        return 'redis';
    }
}
