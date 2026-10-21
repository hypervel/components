<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Cache;
use Redis as PhpRedis;

use function Hypervel\Coroutine\parallel;

/**
 * Integration tests for Redis connection pinning.
 *
 * Verifies explicit connection pinning and that repository callbacks do not
 * retain a connection while running arbitrary user code.
 */
class ConnectionPinningIntegrationTest extends RedisCacheIntegrationTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->string('cache.stores.redis.connection');
        $config->set("database.redis.{$connection}.pool.min_retained_connections", 1);
        $config->set("database.redis.{$connection}.pool.max_connections", 1);
        $config->set("database.redis.{$connection}.pool.wait_timeout", 0.25);
    }

    public function testWithPinnedConnectionReusesConnection(): void
    {
        $store = $this->store();
        $pool = $this->app->make(PoolManager::class)->pool($store->connection()->getName());

        // Multiple operations inside a pinned scope should all succeed
        // using a single pool connection
        $result = $store->withPinnedConnection(function () use ($store, $pool) {
            $this->assertSame(1, $pool->getBorrowedCount());
            $store->put('pinned_key_1', 'value_1', 60);
            $store->put('pinned_key_2', 'value_2', 60);

            return [
                $store->get('pinned_key_1'),
                $store->get('pinned_key_2'),
            ];
        });

        $this->assertSame(['value_1', 'value_2'], $result);
        $this->assertSame(0, $pool->getBorrowedCount());
    }

    public function testWithPinnedConnectionIsReentrant(): void
    {
        $store = $this->store();
        $pool = $this->app->make(PoolManager::class)->pool($store->connection()->getName());

        $result = $store->withPinnedConnection(function () use ($store, $pool) {
            $store->put('outer_key', 'outer_value', 60);

            // Nested pin should not double-release
            $result = $store->withPinnedConnection(function () use ($store, $pool) {
                $this->assertSame(1, $pool->getBorrowedCount());
                $store->put('inner_key', 'inner_value', 60);

                return $store->get('outer_key') . ':' . $store->get('inner_key');
            });

            $this->assertSame(1, $pool->getBorrowedCount());

            return $result;
        });

        $this->assertSame('outer_value:inner_value', $result);
        $this->assertSame(0, $pool->getBorrowedCount());

        // Both keys should still be accessible after the pinned scope
        $this->assertSame('outer_value', Cache::get('outer_key'));
        $this->assertSame('inner_value', Cache::get('inner_key'));
    }

    public function testRememberReleasesItsConnectionBeforeInvokingTheCallback(): void
    {
        $callCount = 0;

        $result = Cache::remember('pinned_remember', 60, function () use (&$callCount) {
            ++$callCount;

            [$stored] = parallel([
                fn (): bool => Cache::put('remember_callback_key', 'callback_value', 60),
            ]);

            $this->assertTrue($stored);

            return 'computed_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
        $this->assertSame('callback_value', Cache::get('remember_callback_key'));

        // Second call should return cached value
        $result = Cache::remember('pinned_remember', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testRememberForeverReleasesItsConnectionBeforeInvokingTheCallback(): void
    {
        $callCount = 0;

        $result = Cache::rememberForever('pinned_forever', function () use (&$callCount) {
            ++$callCount;

            [$stored] = parallel([
                fn (): bool => Cache::put('remember_forever_callback_key', 'callback_value', 60),
            ]);

            $this->assertTrue($stored);

            return 'forever_value';
        });

        $this->assertSame('forever_value', $result);
        $this->assertSame(1, $callCount);
        $this->assertSame('callback_value', Cache::get('remember_forever_callback_key'));

        // Second call should return cached value
        $result = Cache::rememberForever('pinned_forever', function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('forever_value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testAllModeFlushScansAndDeletesChunksWithOnePooledConnection(): void
    {
        $this->setTagMode(TagMode::All);

        $redis = $this->redis();
        $prefix = $this->getCachePrefix();
        $tagKey = $this->allModeTagKey('bulk');
        $expiresAt = time() + 60;

        if ($this->usingRedisCluster()) {
            $redis->withConnection(function (RedisConnection $connection) use ($prefix, $tagKey, $expiresAt): void {
                for ($i = 1; $i <= 1001; ++$i) {
                    $connection->set($prefix . "bulk:{$i}", "value:{$i}", 60);
                    $connection->zAdd($tagKey, $expiresAt, "bulk:{$i}");
                }
            }, transform: false);
        } else {
            $results = $redis->pipeline(function (PhpRedis $pipeline) use ($prefix, $tagKey, $expiresAt): void {
                for ($i = 1; $i <= 1001; ++$i) {
                    $pipeline->set($prefix . "bulk:{$i}", "value:{$i}", 60);
                    $pipeline->zAdd($tagKey, $expiresAt, "bulk:{$i}");
                }
            });

            $this->assertIsArray($results);
        }

        $this->assertSame(1001, $redis->zCard($tagKey));
        $this->assertSame('value:1', $redis->get($prefix . 'bulk:1'));

        Cache::tags(['bulk'])->flush();

        $this->assertSame(0, $redis->zCard($tagKey));
        $this->assertSame(0, $redis->exists($prefix . 'bulk:1'));
        $this->assertSame(0, $redis->exists($prefix . 'bulk:1001'));
    }

    public function testAnyModeOperationsReuseTheirHeldConnection(): void
    {
        $this->setTagMode(TagMode::Any);
        $cache = Cache::tags(['values']);

        $this->assertTrue($cache->add('key', 1, 60));
        $this->assertTrue($cache->put('key', 2, 60));
        $this->assertTrue($cache->forever('key', 3));
        $this->assertSame(5, $cache->increment('key', 2));
        $this->assertSame(4, $cache->decrement('key'));
        $this->assertTrue(Cache::touch('key', 120));
        $this->assertTrue(Cache::forget('key'));
    }

    public function testAllModePruneReusesItsHeldConnection(): void
    {
        $this->setTagMode(TagMode::All);
        $cache = Cache::tags(['orphan']);
        $cache->forever('key', 'value');
        $cache->forget('key');

        $result = $this->store()->allTagOps()->prune()->execute();

        $this->assertSame(1, $result['orphans_removed']);
        $this->assertSame([], $this->getAllModeTagEntries('orphan'));
    }

    public function testAnyModeFlushScansAndDeletesChunksWithOnePooledConnection(): void
    {
        $this->setTagMode(TagMode::Any);
        $cache = Cache::tags(['bulk']);

        for ($index = 1; $index <= 1001; ++$index) {
            $cache->forever('bulk:' . $index, 'value:' . $index);
        }

        $this->assertTrue($cache->flush());

        $this->assertNull(Cache::get('bulk:1'));
        $this->assertNull(Cache::get('bulk:1001'));
        $this->assertSame(0, $this->redis()->hLen($this->anyModeTagKey('bulk')));
        $this->assertFalse($this->anyModeRegistryHasTag('bulk'));
    }
}
