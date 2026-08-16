<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Integration tests for Laravel-style result transforms (call{Name} methods).
 *
 * These verify that the argument reordering and type conversions in
 * RedisConnection's transform methods work correctly against real Redis.
 */
class TransformIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    public function testGetReturnsNullForMissingKey(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // With transform enabled (default), get() returns null instead of false
        $result = $redis->get('nonexistent_key');

        $this->assertNull($result);
    }

    public function testGetReturnsValueForExistingKey(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('existing', 'hello');

        $result = $redis->get('existing');

        $this->assertSame('hello', $result);
    }

    public function testSetWithExpiryAndFlag(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Laravel-style: set(key, value, 'EX', seconds, 'NX')
        // Transform converts to phpredis: set(key, value, ['NX', 'EX' => seconds])
        $result = $redis->set('transform_set', 'value', 'EX', 600, 'NX');

        $this->assertTrue($result);
        $this->assertSame('value', $redis->get('transform_set'));

        // TTL should be set
        $ttl = $redis->ttl('transform_set');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    public function testSetWithExpiryNxFailsWhenKeyExists(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('transform_set_nx', 'first');

        // NX flag should prevent overwriting
        $result = $redis->set('transform_set_nx', 'second', 'EX', 600, 'NX');

        $this->assertFalse($result);
        $this->assertSame('first', $redis->get('transform_set_nx'));
    }

    public function testSetnxReturnsIntNotBool(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Transform converts bool to int: true → 1
        $result = $redis->setnx('setnx_key', 'value');

        $this->assertSame(1, $result);

        // Second call should return 0 (key already exists)
        $result = $redis->setnx('setnx_key', 'other');

        $this->assertSame(0, $result);
    }

    public function testMgetTransformsFalseToNull(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('mget1', 'value1');
        $redis->set('mget3', 'value3');

        // mget2 doesn't exist — transform converts false to null
        $result = $redis->mget(['mget1', 'mget2', 'mget3']);

        $this->assertSame(['value1', null, 'value3'], $result);
    }

    public function testMgetReturnsAnEmptyArrayForEmptyKeys(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));

        $this->assertSame([], $redis->mget([]));
    }

    public function testEvalReordersArguments(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('eval_key', 'eval_value');

        // Laravel-style: eval(script, numKeys, key1, ...)
        // Transform reorders to phpredis: eval(script, [key1, ...], numKeys)
        $result = $redis->eval('return redis.call("GET", KEYS[1])', 1, 'eval_key');

        $this->assertSame('eval_value', $result);
    }

    public function testEvalWithMultipleKeysAndArgs(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Set two keys, then use eval with 2 KEYS + 1 ARGV
        $redis->set('{eval}:k1', 'v1');
        $redis->set('{eval}:k2', 'v2');

        $result = $redis->eval(
            'return {redis.call("GET", KEYS[1]), redis.call("GET", KEYS[2]), ARGV[1]}',
            2,
            '{eval}:k1',
            '{eval}:k2',
            'extra_arg'
        );

        $this->assertSame(['v1', 'v2', 'extra_arg'], $result);
    }

    public function testEvalNormalizesNestedNilReplies(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $result = $redis->eval('return {1, {false, 2}}', 0);

        $this->assertSame([1, [false, 2]], $result);
    }

    public function testHmsetWithArrayForm(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Array form: hmset(key, ['field1' => 'value1', 'field2' => 'value2'])
        $result = $redis->hmset('hash', ['field1' => 'val1', 'field2' => 'val2']);

        $this->assertTrue($result);
        $this->assertSame('val1', $redis->hget('hash', 'field1'));
        $this->assertSame('val2', $redis->hget('hash', 'field2'));
    }

    public function testHmsetWithAlternatingKeyValuePairs(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Alternating form: hmset(key, field1, value1, field2, value2)
        // Transform converts to: hmset(key, ['field1' => 'value1', 'field2' => 'value2'])
        $result = $redis->hmset('hash_alt', 'f1', 'v1', 'f2', 'v2');

        $this->assertTrue($result);
        $this->assertSame('v1', $redis->hget('hash_alt', 'f1'));
        $this->assertSame('v2', $redis->hget('hash_alt', 'f2'));
    }

    public function testHmgetReturnsIndexedValues(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->hset('hmget_hash', 'field1', 'val1');
        $redis->hset('hmget_hash', 'field2', 'val2');

        // Transform strips keys, returns just the values as indexed array
        $result = $redis->hmget('hmget_hash', ['field1', 'field2']);

        $this->assertSame(['val1', 'val2'], $result);
    }

    public function testHmgetWithMultipleStringArgs(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->hset('hmget_hash2', 'a', '1');
        $redis->hset('hmget_hash2', 'b', '2');

        // Multiple string args form: hmget(key, field1, field2)
        $result = $redis->hmget('hmget_hash2', 'a', 'b');

        $this->assertSame(['1', '2'], $result);
    }

    public function testHsetnxReturnsInt(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Transform converts bool to int: true → 1
        $result = $redis->hsetnx('hsetnx_hash', 'field', 'value');

        $this->assertSame(1, $result);

        // Second call returns 0 (field already exists)
        $result = $redis->hsetnx('hsetnx_hash', 'field', 'other');

        $this->assertSame(0, $result);
    }

    public function testLremSwapsArguments(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->rpush('lrem_list', 'a');
        $redis->rpush('lrem_list', 'b');
        $redis->rpush('lrem_list', 'a');
        $redis->rpush('lrem_list', 'c');
        $redis->rpush('lrem_list', 'a');

        // Laravel-style: lrem(key, count, value)
        // Transform reorders to phpredis: lRem(key, value, count)
        $removed = $redis->lrem('lrem_list', 2, 'a');

        $this->assertSame(2, $removed);

        // Should have one 'a' remaining (removed from head)
        $remaining = $redis->lrange('lrem_list', 0, -1);
        $this->assertSame(['b', 'c', 'a'], $remaining);
    }

    public function testSpopWithoutCountReturnsSingleElement(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->sadd('spop_set', 'member1');

        // Without count: returns a single string (not array)
        $result = $redis->spop('spop_set');

        $this->assertSame('member1', $result);
    }

    public function testSpopWithCountReturnsArray(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->sadd('spop_set2', 'a', 'b', 'c');

        // With count: returns an array
        $result = $redis->spop('spop_set2', 2);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testSpopReturnsEmptySetAsFalse(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // spop on non-existent set returns false
        $result = $redis->spop('empty_set');

        $this->assertFalse($result);
    }

    public function testBlpopReturnsNullOnTimeout(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // blpop with 1 second timeout on empty list returns null (not empty array)
        $result = $redis->blpop('empty_list', 1);

        $this->assertNull($result);
    }

    public function testBrpopReturnsNullOnTimeout(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // brpop with 1 second timeout on empty list returns null (not empty array)
        $result = $redis->brpop('empty_list', 1);

        $this->assertNull($result);
    }

    public function testBlpopReturnsArrayOnSuccess(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->rpush('blpop_list', 'item1');

        $result = $redis->blpop('blpop_list', 1);

        $this->assertSame(['blpop_list', 'item1'], $result);
    }

    public function testZaddWithOptionsAndScoreMemberPairs(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Laravel-style: zadd(key, 'NX', score, member, score, member)
        // Transform parses options and reorders for phpredis
        $result = $redis->zadd('zset', 'NX', 1.0, 'member1', 2.0, 'member2');

        $this->assertSame(2, $result);
        $this->assertSame(1.0, $redis->zscore('zset', 'member1'));
        $this->assertSame(2.0, $redis->zscore('zset', 'member2'));
    }

    public function testZaddWithArrayForm(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        // Array form: zadd(key, ['member' => score])
        $result = $redis->zadd('zset2', ['mem1' => 1.0, 'mem2' => 2.0]);

        $this->assertSame(2, $result);
        $this->assertSame(1.0, $redis->zscore('zset2', 'mem1'));
    }

    public function testZrangebyscoreConvertsLimitOption(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->zadd('zrange', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5]);

        // Laravel-style limit with offset/count keys
        // Transform converts to indexed array [offset, count]
        $result = $redis->zrangebyscore('zrange', '1', '5', [
            'limit' => ['offset' => 1, 'count' => 2],
        ]);

        $this->assertSame(['b', 'c'], $result);
    }

    public function testZrevrangebyscoreConvertsLimitOption(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->zadd('zrevrange', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5]);

        $result = $redis->zrevrangebyscore('zrevrange', '5', '1', [
            'limit' => ['offset' => 1, 'count' => 2],
        ]);

        $this->assertSame(['d', 'c'], $result);
    }

    public function testFlushdbAsync(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));

        $redis->set('async_flush_key', 'value');

        // Transform converts 'ASYNC' string to flushdb(true)
        $result = $redis->flushdb('ASYNC');

        $this->assertTrue($result);
    }

    public function testPingUsesTheConfiguredTopology(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));

        $this->assertTrue($redis->ping());
    }

    public function testInfoHonorsTheServerSectionFilter(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $info = $redis->info('server');

        $this->assertIsArray($info);
        $this->assertArrayHasKey('redis_version', $info);
        $this->assertArrayNotHasKey('used_memory', $info);
    }

    public function testExecuteRawDelegatesToRawCommand(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('raw_key', 'raw_value');

        // Transform: executeRaw(['GET', 'raw_key']) → rawCommand('GET', 'raw_key')
        $result = $redis->executeRaw(['GET', 'raw_key']);

        $this->assertSame('raw_value', $result);
    }

    public function testExecuteRawNormalizesAMissingValue(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $result = $redis->executeRaw(['GET', 'missing_raw_key']);

        $this->assertFalse($result);
    }

    public function testEvalshaLoadsAndExecutesScript(): void
    {
        $redis = Redis::connection($this->createRedisConnectionWithPrefix(''));
        $redis->flushdb();

        $redis->set('evalsha_key', 'evalsha_value');

        // Transform: evalsha(script, numKeys, key) → cached evalSha with an eval fallback.
        $result = $redis->evalsha('return redis.call("GET", KEYS[1])', 1, 'evalsha_key');

        $this->assertSame('evalsha_value', $result);
    }
}
