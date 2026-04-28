<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\TestCase;
use Redis;

/**
 * Tests that Redis locks work correctly under various phpredis serializer
 * and compression configurations.
 *
 * Validates the pack() + withConnection() fix on RedisLock::release() and
 * refresh() — Lua ARGV values must be pre-packed when a serializer is
 * configured, because phpredis does NOT auto-serialize eval() ARGV.
 *
 * Unlike Laravel (which sets serializer options on a live client instance),
 * Hypervel uses connection pooling — serializer/compression options must be
 * configured at the connection config level so the pool creates connections
 * with the correct settings.
 */
class PhpRedisCacheLockTest extends TestCase
{
    use InteractsWithRedis;

    public function testRedisLockCanBeAcquiredAndReleasedWithoutSerializationAndCompression()
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithPhpSerialization()
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_PHP,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithJsonSerialization()
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_JSON,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithIgbinarySerialization()
    {
        if (! defined('Redis::SERIALIZER_IGBINARY')) {
            $this->markTestSkipped('Redis extension is not configured to support the igbinary serializer.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_IGBINARY,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithMsgpackSerialization()
    {
        if (! defined('Redis::SERIALIZER_MSGPACK')) {
            $this->markTestSkipped('Redis extension is not configured to support the msgpack serializer.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_MSGPACK,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithLzfCompression()
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support the lzf compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_LZF,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithZstdCompression()
    {
        if (! defined('Redis::COMPRESSION_ZSTD')) {
            $this->markTestSkipped('Redis extension is not configured to support the zstd compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_ZSTD,
            'compression_level' => Redis::COMPRESSION_ZSTD_DEFAULT,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithLz4Compression()
    {
        if (! defined('Redis::COMPRESSION_LZ4')) {
            $this->markTestSkipped('Redis extension is not configured to support the lz4 compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_LZ4,
            'compression_level' => 1,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithSerializationAndCompression()
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support the lzf compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_PHP,
            'compression' => Redis::COMPRESSION_LZF,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    /**
     * Configure a dedicated Redis connection for lock testing with the given options.
     *
     * Creates a 'lock-test' Redis connection with the specified serializer/compression
     * options, points the cache store's lock_connection to it, and purges the cache
     * store so it picks up the new configuration.
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
     * Assert that a lock can be acquired, prevents double acquisition, and releases cleanly.
     */
    protected function assertLockCanBeAcquiredAndReleased(): void
    {
        /** @var \Hypervel\Cache\RedisStore $store */
        $store = Cache::store('redis');

        $store->lock('foo')->forceRelease();
        $lock = $store->lock('foo', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse($store->lock('foo', 10)->get());
        $lock->release();

        // After release, lock should be acquirable again
        $lock = $store->lock('foo', 10);
        $this->assertTrue($lock->get());
        $lock->forceRelease();
    }
}
