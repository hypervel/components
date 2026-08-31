<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\TagMode;
use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Factory as CacheContract;
use Mockery as m;

class BenchmarkContextTest extends RedisCacheIntegrationTestCase
{
    public function testCleanupRemovesEveryBenchmarkKeyFamilyAndPreservesUnrelatedKeysInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        [$benchmarkKeys, $unrelatedKeys] = $this->seedBenchmarkAndUnrelatedKeys();

        $registryKey = $this->anyModeRegistryKey();
        $this->redis()->zadd(
            $registryKey,
            StoreContext::MAX_EXPIRY,
            '_bench:registry-tag',
            StoreContext::MAX_EXPIRY,
            'unrelated-tag',
        );

        $this->benchmarkContext()->cleanup();

        foreach ($benchmarkKeys as $key) {
            $this->assertRedisKeyNotExists($key);
        }

        foreach ($unrelatedKeys as $key) {
            $this->assertRedisKeyExists($key);
        }

        $this->assertFalse($this->redis()->zScore($registryKey, '_bench:registry-tag'));
        $this->assertNotFalse($this->redis()->zScore($registryKey, 'unrelated-tag'));
    }

    public function testCleanupRemovesEveryBenchmarkKeyFamilyAndPreservesUnrelatedKeysInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        [$benchmarkKeys, $unrelatedKeys] = $this->seedBenchmarkAndUnrelatedKeys();

        $registryKey = $this->anyModeRegistryKey();
        $this->redis()->zadd(
            $registryKey,
            StoreContext::MAX_EXPIRY,
            '_bench:registry-tag',
            StoreContext::MAX_EXPIRY,
            'unrelated-tag',
        );

        $this->benchmarkContext()->cleanup();

        foreach ($benchmarkKeys as $key) {
            $this->assertRedisKeyNotExists($key);
        }

        foreach ($unrelatedKeys as $key) {
            $this->assertRedisKeyExists($key);
        }

        $this->assertFalse($this->redis()->zScore($registryKey, '_bench:registry-tag'));
        $this->assertNotFalse($this->redis()->zScore($registryKey, 'unrelated-tag'));
    }

    /**
     * Create a benchmark context for the Redis integration store.
     */
    private function benchmarkContext(): BenchmarkContext
    {
        return new BenchmarkContext(
            storeName: 'redis',
            items: 1,
            tagsPerItem: 1,
            heavyTags: 1,
            command: m::mock(Command::class),
            cacheManager: $this->app->make(CacheContract::class),
        );
    }

    /**
     * Seed every benchmark key family and unrelated boundary keys.
     *
     * @return array{array<string>, array<string>}
     */
    private function seedBenchmarkAndUnrelatedKeys(): array
    {
        $prefix = $this->getCachePrefix();
        $benchmarkKeys = [
            $prefix . '_bench:plain-value',
            $prefix . '0123456789abcdef:_bench:all-mode-value',
            $prefix . '_any:tag:_bench:any-mode-tag:entries',
            $prefix . '_all:tag:_bench:all-mode-tag:entries',
        ];
        $unrelatedKeys = [
            $prefix . 'unrelated-value',
            $prefix . '_benchmark:value',
        ];

        foreach ([...$benchmarkKeys, ...$unrelatedKeys] as $key) {
            $this->redis()->set($key, 'value');
        }

        return [$benchmarkKeys, $unrelatedKeys];
    }
}
