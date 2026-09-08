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
