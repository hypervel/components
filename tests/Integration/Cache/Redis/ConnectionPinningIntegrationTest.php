<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
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

        $app->make('config')->set('database.redis.cache.pool.min_connections', 1);
        $app->make('config')->set('database.redis.cache.pool.max_connections', 1);
        $app->make('config')->set('database.redis.cache.pool.wait_timeout', 0.25);
    }

    public function testWithPinnedConnectionReusesConnection(): void
    {
        $store = $this->store();

        // Multiple operations inside a pinned scope should all succeed
        // using a single pool connection
        $result = $store->withPinnedConnection(function () use ($store) {
            $store->put('pinned_key_1', 'value_1', 60);
            $store->put('pinned_key_2', 'value_2', 60);

            return [
                $store->get('pinned_key_1'),
                $store->get('pinned_key_2'),
            ];
        });

        $this->assertSame(['value_1', 'value_2'], $result);
    }

    public function testWithPinnedConnectionIsReentrant(): void
    {
        $store = $this->store();

        $result = $store->withPinnedConnection(function () use ($store) {
            $store->put('outer_key', 'outer_value', 60);

            // Nested pin should not double-release
            return $store->withPinnedConnection(function () use ($store) {
                $store->put('inner_key', 'inner_value', 60);

                return $store->get('outer_key') . ':' . $store->get('inner_key');
            });
        });

        $this->assertSame('outer_value:inner_value', $result);

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
        $pipeline = $redis->multi(PhpRedis::PIPELINE);
        $prefix = $this->getCachePrefix();
        $tagKey = $this->allModeTagKey('bulk');
        $expiresAt = time() + 60;

        for ($i = 1; $i <= 1001; ++$i) {
            $pipeline->set($prefix . "bulk:{$i}", "value:{$i}", 60);
            $pipeline->zAdd($tagKey, $expiresAt, "bulk:{$i}");
        }

        $this->assertIsArray($pipeline->exec());

        Cache::tags(['bulk'])->flush();

        $this->assertSame(0, $redis->zCard($tagKey));
        $this->assertSame(0, $redis->exists($prefix . 'bulk:1'));
        $this->assertSame(0, $redis->exists($prefix . 'bulk:1001'));
    }
}
