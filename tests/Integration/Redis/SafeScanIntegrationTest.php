<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Integration tests for SafeScan and FlushByPattern operations.
 *
 * These verify that OPT_PREFIX handling works correctly end-to-end:
 * - SafeScan prepends OPT_PREFIX to patterns and strips it from results
 * - FlushByPattern uses SafeScan + UNLINK to delete matching keys
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class SafeScanIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get('config');
        $this->configureRedisForTesting($config);
    }

    public function testSafeScanYieldsKeysWithoutPrefix()
    {
        $prefix = 'safescan_test:';
        $connectionName = $this->createRedisConnectionWithPrefix($prefix);
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        // Create keys via the prefixed connection
        $redis->set('key1', 'val1');
        $redis->set('key2', 'val2');
        $redis->set('key3', 'val3');

        // safeScan should yield keys WITHOUT the prefix
        $keys = $redis->withConnection(function (RedisConnection $connection) {
            return iterator_to_array($connection->safeScan('key*'));
        }, transform: false);

        sort($keys);

        $this->assertSame(['key1', 'key2', 'key3'], $keys);

        // Verify these keys work with get() (which auto-adds prefix)
        $this->assertSame('val1', $redis->get('key1'));
    }

    public function testSafeScanWithoutPrefix()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        $redis->set('noprefix:1', 'a');
        $redis->set('noprefix:2', 'b');
        $redis->set('other:1', 'c');

        $keys = $redis->withConnection(function (RedisConnection $connection) {
            return iterator_to_array($connection->safeScan('noprefix:*'));
        }, transform: false);

        sort($keys);

        $this->assertSame(['noprefix:1', 'noprefix:2'], $keys);
    }

    public function testSafeScanMatchesPatternOnly()
    {
        $prefix = 'pattern_test:';
        $connectionName = $this->createRedisConnectionWithPrefix($prefix);
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        $redis->set('cache:user:1', 'u1');
        $redis->set('cache:user:2', 'u2');
        $redis->set('cache:post:1', 'p1');
        $redis->set('session:1', 's1');

        $keys = $redis->withConnection(function (RedisConnection $connection) {
            return iterator_to_array($connection->safeScan('cache:user:*'));
        }, transform: false);

        sort($keys);

        $this->assertSame(['cache:user:1', 'cache:user:2'], $keys);
    }

    public function testFlushByPatternDeletesMatchingKeys()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        // Create keys: some match pattern, some don't
        $redis->set('flush:match:1', 'a');
        $redis->set('flush:match:2', 'b');
        $redis->set('flush:match:3', 'c');
        $redis->set('flush:keep:1', 'x');
        $redis->set('flush:keep:2', 'y');

        $deleted = $redis->withConnection(function (RedisConnection $connection) {
            return $connection->flushByPattern('flush:match:*');
        }, transform: false);

        $this->assertSame(3, $deleted);

        // Matching keys should be gone
        $this->assertNull($redis->get('flush:match:1'));
        $this->assertNull($redis->get('flush:match:2'));
        $this->assertNull($redis->get('flush:match:3'));

        // Non-matching keys should remain
        $this->assertSame('x', $redis->get('flush:keep:1'));
        $this->assertSame('y', $redis->get('flush:keep:2'));
    }

    public function testFlushByPatternReturnsDeletedCount()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        for ($i = 0; $i < 15; ++$i) {
            $redis->set("count:key:{$i}", "val{$i}");
        }

        $deleted = $redis->withConnection(function (RedisConnection $connection) {
            return $connection->flushByPattern('count:key:*');
        }, transform: false);

        $this->assertSame(15, $deleted);
    }

    public function testFlushByPatternReturnsZeroWhenNoKeysMatch()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        $deleted = $redis->withConnection(function (RedisConnection $connection) {
            return $connection->flushByPattern('nonexistent:*');
        }, transform: false);

        $this->assertSame(0, $deleted);
    }

    public function testFlushByPatternWithPrefixHandlesDoublePrefix()
    {
        $prefix = 'flushprefix:';
        $connectionName = $this->createRedisConnectionWithPrefix($prefix);
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        // Create keys via prefixed connection (stored as "flushprefix:cache:1" in Redis)
        $redis->set('cache:1', 'a');
        $redis->set('cache:2', 'b');
        $redis->set('other:1', 'c');

        // flushByPattern should handle OPT_PREFIX correctly — no double prefix
        $deleted = $redis->withConnection(function (RedisConnection $connection) {
            return $connection->flushByPattern('cache:*');
        }, transform: false);

        $this->assertSame(2, $deleted);

        // Verify matching keys are gone
        $this->assertNull($redis->get('cache:1'));
        $this->assertNull($redis->get('cache:2'));

        // Verify non-matching key remains
        $this->assertSame('c', $redis->get('other:1'));
    }

    public function testFlushByPatternViaRedisFacade()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $redis = Redis::connection($connectionName);
        $redis->flushdb();

        $redis->set('facade:flush:1', 'a');
        $redis->set('facade:flush:2', 'b');
        $redis->set('facade:keep:1', 'c');

        // Redis::flushByPattern() handles connection lifecycle automatically
        $deleted = $redis->flushByPattern('facade:flush:*');

        $this->assertSame(2, $deleted);
        $this->assertNull($redis->get('facade:flush:1'));
        $this->assertSame('c', $redis->get('facade:keep:1'));
    }
}
