<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\TagMode;
use Hypervel\Redis\RedisConnection;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;

class SerializationIntegrationTest extends RedisCacheIntegrationTestCase
{
    #[TestWith(['putMany'])]
    #[TestWith(['put'])]
    #[TestWith(['add'])]
    #[TestWith(['forever'])]
    public function testCacheWritesApplyCompressionWithoutANativeSerializer(string $operation): void
    {
        $this->configureCompression();

        if ($operation !== 'putMany') {
            $this->setTagMode(TagMode::Any);
        }

        $cache = $operation === 'putMany' ? $this->cache() : $this->cache()->tags(['compressed']);
        $value = str_repeat('cache-value', 100);

        $this->assertTrue(match ($operation) {
            'putMany' => $cache->putMany(['compressed' => $value], 60),
            'put' => $cache->put('compressed', $value, 60),
            'add' => $cache->add('compressed', $value, 60),
            'forever' => $cache->forever('compressed', $value),
        });

        $redis = $this->redis();
        $key = $this->getCachePrefix() . 'compressed';
        $expected = $redis->withConnection(
            static fn (RedisConnection $connection): string => $connection->pack([serialize($value)])[0],
        );

        // Round trips also succeed for uncompressed bytes, so inspect the stored representation.
        $this->assertSame($expected, $redis->withoutSerializationOrCompression(static fn (): mixed => $redis->get($key)));
        $this->assertSame($value, $this->cache()->get('compressed'));
    }

    #[TestWith(['native'])]
    #[TestWith(['compression'])]
    public function testAllModeAddPreservesConfiguredSerialization(string $mode): void
    {
        if ($mode === 'compression') {
            $this->configureCompression();
        } else {
            config(['cache.stores.redis.connection' => $this->createRedisConnectionWithOptions('cache_serialized', [
                'serializer' => Redis::SERIALIZER_PHP,
            ])]);
        }

        $this->setTagMode(TagMode::All);
        $cache = $this->cache()->tags(['serialized']);
        $value = ['content' => str_repeat('cache-value', 100)];

        $this->assertTrue($cache->add('key', $value, 60));
        $this->assertSame($value, $cache->get('key'));
    }

    #[TestWith(['increment', 'php', 9007199254740993])]
    #[TestWith(['decrement', 'php', -9007199254740993])]
    #[TestWith(['increment', 'igbinary', 9007199254740993])]
    #[TestWith(['decrement', 'igbinary', -9007199254740993])]
    #[TestWith(['increment', 'compression', 9007199254740993])]
    #[TestWith(['decrement', 'compression', -9007199254740993])]
    public function testAllModeCounterRepliesPreserveIntegersWithSerializationOptions(string $method, string $mode, int $expected): void
    {
        if ($mode === 'compression') {
            $this->configureCompression();
        } else {
            if ($mode === 'igbinary' && ! defined('Redis::SERIALIZER_IGBINARY')) {
                $this->markTestSkipped('Redis extension is not configured to support igbinary serialization.');
            }

            config(['cache.stores.redis.connection' => $this->createRedisConnectionWithOptions('cache_serialized', [
                'serializer' => $mode === 'php' ? Redis::SERIALIZER_PHP : Redis::SERIALIZER_IGBINARY,
            ])]);
        }

        $this->setTagMode(TagMode::All);

        // Redis creates the counter as a plain integer, bypassing the client's serializer.
        $this->assertSame($expected, $this->cache()->tags(['counter'])->{$method}('key', 9007199254740993));
    }

    public function testPutManyPreservesNumbersWhenPackingIgnoresThem(): void
    {
        if (! defined('Redis::OPT_PACK_IGNORE_NUMBERS')) {
            $this->markTestSkipped('PhpRedis does not support OPT_PACK_IGNORE_NUMBERS.');
        }

        $this->configureCompression(ignoreNumbers: true);

        $this->assertTrue($this->cache()->putMany(['counter' => 5], 60));

        $redis = $this->redis();
        $key = $this->getCachePrefix() . 'counter';

        $this->assertSame('5', $redis->withoutSerializationOrCompression(static fn (): mixed => $redis->get($key)));
        $this->assertSame(6, $this->cache()->increment('counter'));
    }

    /**
     * Configure compression on a fresh connection before resolving the cache store.
     */
    protected function configureCompression(bool $ignoreNumbers = false): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support the lzf compression.');
        }

        $options = [
            'serializer' => Redis::SERIALIZER_NONE,
            'compression' => Redis::COMPRESSION_LZF,
        ];

        if ($ignoreNumbers) {
            $options['pack_ignore_numbers'] = true;
        }

        config(['cache.stores.redis.connection' => $this->createRedisConnectionWithOptions('cache_compressed', $options)]);
    }
}
