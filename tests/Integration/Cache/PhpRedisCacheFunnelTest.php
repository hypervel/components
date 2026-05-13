<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\TestCase;
use Redis;

/**
 * Tests that Cache::funnel() works correctly under various phpredis serializer
 * and compression configurations.
 *
 * Validates the pack() + withConnection() fix on RedisConcurrencyLimiter::acquire():
 * Lua ARGV must be pre-packed when a serializer is configured, because phpredis
 * does NOT auto-serialize eval() ARGV. Without packing, the funnel slot's stored
 * owner (raw) does not match what RedisLock::release() compares against (packed),
 * causing release to silently fail and the slot to leak until releaseAfter.
 *
 * Mirrors PhpRedisCacheLockTest in structure — funnel reuses the lock_connection
 * path, so the same serializer/compression matrix applies.
 */
class PhpRedisCacheFunnelTest extends TestCase
{
    use InteractsWithRedis;

    public function testFunnelReleasesSlotWithoutSerializationAndCompression()
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithPhpSerialization()
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_PHP,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithJsonSerialization()
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_JSON,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithIgbinarySerialization()
    {
        if (! defined('Redis::SERIALIZER_IGBINARY')) {
            $this->markTestSkipped('Redis extension is not configured to support the igbinary serializer.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_IGBINARY,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithMsgpackSerialization()
    {
        if (! defined('Redis::SERIALIZER_MSGPACK')) {
            $this->markTestSkipped('Redis extension is not configured to support the msgpack serializer.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_MSGPACK,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithLzfCompression()
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support the lzf compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_LZF,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithZstdCompression()
    {
        if (! defined('Redis::COMPRESSION_ZSTD')) {
            $this->markTestSkipped('Redis extension is not configured to support the zstd compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_ZSTD,
            'compression_level' => Redis::COMPRESSION_ZSTD_DEFAULT,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithLz4Compression()
    {
        if (! defined('Redis::COMPRESSION_LZ4')) {
            $this->markTestSkipped('Redis extension is not configured to support the lz4 compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_LZ4,
            'compression_level' => 1,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    public function testFunnelReleasesSlotWithSerializationAndCompression()
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support the lzf compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_PHP,
            'compression' => Redis::COMPRESSION_LZF,
        ]);

        $this->assertFunnelAcquiresAndReleases();
    }

    /**
     * Configure a dedicated Redis connection for funnel testing with the given options.
     *
     * Creates a 'lock-test' Redis connection with the specified serializer/compression
     * options, points the cache store's lock_connection to it, and purges the cache
     * store so it picks up the new configuration. Identical to PhpRedisCacheLockTest's
     * helper since the funnel uses the same lock_connection path.
     */
    protected function configureLockConnection(array $options): void
    {
        $baseConfig = $this->app['config']->get('database.redis.default');

        $this->app['config']->set('database.redis.lock-test', array_merge($baseConfig, [
            'options' => $options,
        ]));

        $this->app['config']->set('cache.stores.redis.connection', 'default');
        $this->app['config']->set('cache.stores.redis.lock_connection', 'lock-test');

        Cache::forgetDriver('redis');
    }

    /**
     * Assert that Cache::funnel() acquires a slot, runs the callback, and releases
     * the slot cleanly — verified by a second consecutive call under block(0)
     * succeeding immediately.
     *
     * If the owner-packing fix is missing (raw $id stored in Lua, packed compared
     * in RedisLock::release()), the second call throws LimiterTimeoutException
     * because the slot from the first call was never actually released.
     */
    protected function assertFunnelAcquiresAndReleases(): void
    {
        $repository = Cache::store('redis');
        $repository->lock('test1')->forceRelease();

        $first = $repository->funnel('test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'first');

        $this->assertSame('first', $first);

        $second = $repository->funnel('test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'second');

        $this->assertSame('second', $second);

        $repository->lock('test1')->forceRelease();
    }
}
