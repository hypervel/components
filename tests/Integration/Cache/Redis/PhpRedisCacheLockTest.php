<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;

/**
 * Configure serialization and compression on the pool so every connection
 * uses the same options. Lock release and refresh must pack the owner for
 * Lua with those options, since phpredis does not serialize eval() ARGV.
 */
class PhpRedisCacheLockTest extends TestCase
{
    use InteractsWithRedis;

    public function testRedisLockCanBeAcquiredAndReleasedWithoutSerializationAndCompression(): void
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithPhpSerialization(): void
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_PHP,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithJsonSerialization(): void
    {
        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_JSON,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithIgbinarySerialization(): void
    {
        if (! defined('Redis::SERIALIZER_IGBINARY')) {
            $this->markTestSkipped('Redis extension is not configured to support the igbinary serializer.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_IGBINARY,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithMsgpackSerialization(): void
    {
        if (! defined('Redis::SERIALIZER_MSGPACK')) {
            $this->markTestSkipped('Redis extension is not configured to support the msgpack serializer.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_MSGPACK,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithLzfCompression(): void
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

    #[TestWith(['COMPRESSION_ZSTD_DEFAULT'])]
    #[TestWith(['COMPRESSION_ZSTD_MAX'])]
    public function testRedisLockCanBeAcquiredAndReleasedWithZstdCompression(string $compressionLevel): void
    {
        if (! defined('Redis::COMPRESSION_ZSTD')) {
            $this->markTestSkipped('Redis extension is not configured to support the zstd compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_ZSTD,
            'compression_level' => constant(Redis::class . '::' . $compressionLevel),
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    #[TestWith([1])]
    #[TestWith([3])]
    #[TestWith([12])]
    public function testRedisLockCanBeAcquiredAndReleasedWithLz4Compression(int $compressionLevel): void
    {
        if (! defined('Redis::COMPRESSION_LZ4')) {
            $this->markTestSkipped('Redis extension is not configured to support the lz4 compression.');
        }

        $this->configureLockConnection([
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_LZ4,
            'compression_level' => $compressionLevel,
        ]);

        $this->assertLockCanBeAcquiredAndReleased();
    }

    public function testRedisLockCanBeAcquiredAndReleasedWithSerializationAndCompression(): void
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
     * @param array<string, mixed> $options
     */
    protected function configureLockConnection(array $options): void
    {
        $config = $this->app->make('config');
        $config->set('cache.stores.redis.connection', 'default');
        $config->set('cache.stores.redis.lock_connection', $this->createRedisConnectionWithOptions('lock-test', $options));

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
        $this->assertNull($store->lockConnection()->get($store->getPrefix() . 'foo'));

        $lock = $store->lock('foo', 3);
        $this->assertTrue($lock->get());
        $this->assertFalse($store->lock('foo', 3)->get());

        usleep(1_100_000);

        $decayedLifetime = $lock->getRemainingLifetime();
        $this->assertNotNull($decayedLifetime);

        $this->assertTrue($lock->refresh());

        $refreshedLifetime = $lock->getRemainingLifetime();
        $this->assertNotNull($refreshedLifetime);
        $this->assertGreaterThan($decayedLifetime, $refreshedLifetime);

        $lock->release();
        $this->assertNull($store->lockConnection()->get($store->getPrefix() . 'foo'));

        $lock = $store->lock('foo', 10);
        $this->assertTrue($lock->get());
        $lock->forceRelease();
    }
}
