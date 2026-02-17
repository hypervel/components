<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Coroutine\Parallel;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for concurrent cache operations.
 *
 * Tests verify that rapid sequential operations behave correctly,
 * simulating concurrent access patterns.
 *
 * @internal
 * @coversNothing
 */
class ConcurrencyIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // CONCURRENT WRITES - BOTH MODES
    // =========================================================================

    public function testAllModeConcurrentWritesToSameKey(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertConcurrentWritesToSameKey();
    }

    public function testAnyModeConcurrentWritesToSameKey(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertConcurrentWritesToSameKey();
    }

    private function assertConcurrentWritesToSameKey(): void
    {
        $iterations = 10;
        $key = 'concurrent-key';
        $isAnyMode = $this->getTagMode()->isAnyMode();

        for ($i = 0; $i < $iterations; ++$i) {
            Cache::tags(['concurrent'])->put($key, "value-{$i}", 60);
        }

        // Last write should win
        $value = $isAnyMode ? Cache::get($key) : Cache::tags(['concurrent'])->get($key);
        $this->assertSame('value-' . ($iterations - 1), $value);
    }

    // =========================================================================
    // CONCURRENT TAG FLUSHES - BOTH MODES
    // =========================================================================

    public function testAllModeConcurrentTagFlushes(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertConcurrentTagFlushes();
    }

    public function testAnyModeConcurrentTagFlushes(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertConcurrentTagFlushes();
    }

    private function assertConcurrentTagFlushes(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();

        // Create items with overlapping tags
        for ($i = 0; $i < 20; ++$i) {
            Cache::tags(['shared', "unique-{$i}"])->put("item-{$i}", "value-{$i}", 60);
        }

        // Multiple flushes
        Cache::tags(['shared'])->flush();
        Cache::tags(['unique-5'])->flush();
        Cache::tags(['unique-10'])->flush();

        // All items should be gone (since they all have 'shared' tag)
        for ($i = 0; $i < 20; ++$i) {
            $this->assertNull(
                $isAnyMode ? Cache::get("item-{$i}") : Cache::tags(['shared', "unique-{$i}"])->get("item-{$i}")
            );
        }
    }

    // =========================================================================
    // CONCURRENT ADDS - BOTH MODES
    // =========================================================================

    public function testAllModeConcurrentAdds(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertConcurrentAdds();
    }

    public function testAnyModeConcurrentAdds(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertConcurrentAdds();
    }

    private function assertConcurrentAdds(): void
    {
        $key = 'add-race';
        $isAnyMode = $this->getTagMode()->isAnyMode();

        // First add should succeed
        $result1 = Cache::tags(['race'])->add($key, 'first', 60);
        $this->assertTrue($result1);

        // Subsequent adds should fail
        $result2 = Cache::tags(['race'])->add($key, 'second', 60);
        $this->assertFalse($result2);

        $result3 = Cache::tags(['race'])->add($key, 'third', 60);
        $this->assertFalse($result3);

        // Value should still be the first
        $value = $isAnyMode ? Cache::get($key) : Cache::tags(['race'])->get($key);
        $this->assertSame('first', $value);
    }

    // =========================================================================
    // CONCURRENT INCREMENTS/DECREMENTS - BOTH MODES
    // =========================================================================

    public function testAllModeConcurrentIncrements(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertConcurrentIncrements();
    }

    public function testAnyModeConcurrentIncrements(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertConcurrentIncrements();
    }

    private function assertConcurrentIncrements(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        Cache::tags(['counters'])->put('counter', 0, 60);

        $increments = 100;

        for ($i = 0; $i < $increments; ++$i) {
            Cache::tags(['counters'])->increment('counter');
        }

        $value = $isAnyMode ? Cache::get('counter') : Cache::tags(['counters'])->get('counter');
        $this->assertEquals($increments, $value);
    }

    public function testAllModeConcurrentDecrements(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertConcurrentDecrements();
    }

    public function testAnyModeConcurrentDecrements(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertConcurrentDecrements();
    }

    private function assertConcurrentDecrements(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        Cache::tags(['counters'])->put('counter', 1000, 60);

        $decrements = 100;

        for ($i = 0; $i < $decrements; ++$i) {
            Cache::tags(['counters'])->decrement('counter');
        }

        $value = $isAnyMode ? Cache::get('counter') : Cache::tags(['counters'])->get('counter');
        $this->assertEquals(1000 - $decrements, $value);
    }

    // =========================================================================
    // RACE BETWEEN PUT AND FLUSH - BOTH MODES
    // =========================================================================

    public function testAllModeRaceBetweenPutAndFlush(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertRaceBetweenPutAndFlush();
    }

    public function testAnyModeRaceBetweenPutAndFlush(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertRaceBetweenPutAndFlush();
    }

    private function assertRaceBetweenPutAndFlush(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();

        // Add initial items
        for ($i = 0; $i < 10; ++$i) {
            Cache::tags(['race-flush'])->put("initial-{$i}", 'value', 60);
        }

        // Flush
        Cache::tags(['race-flush'])->flush();

        // Immediately add new items
        for ($i = 0; $i < 5; ++$i) {
            Cache::tags(['race-flush'])->put("new-{$i}", 'value', 60);
        }

        // New items should exist
        for ($i = 0; $i < 5; ++$i) {
            $value = $isAnyMode ? Cache::get("new-{$i}") : Cache::tags(['race-flush'])->get("new-{$i}");
            $this->assertSame('value', $value);
        }

        // Old items should be gone
        for ($i = 0; $i < 10; ++$i) {
            $value = $isAnyMode ? Cache::get("initial-{$i}") : Cache::tags(['race-flush'])->get("initial-{$i}");
            $this->assertNull($value);
        }
    }

    // =========================================================================
    // OPERATIONS ON DIFFERENT TAGS - BOTH MODES
    // =========================================================================

    public function testAllModeOperationsOnDifferentTags(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertOperationsOnDifferentTags();
    }

    public function testAnyModeOperationsOnDifferentTags(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertOperationsOnDifferentTags();
    }

    private function assertOperationsOnDifferentTags(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();

        // Set up items with different tags
        Cache::tags(['tag-a'])->put('item-a', 'value-a', 60);
        Cache::tags(['tag-b'])->put('item-b', 'value-b', 60);
        Cache::tags(['tag-c'])->put('item-c', 'value-c', 60);

        // Operations
        Cache::tags(['tag-a'])->flush();
        Cache::tags(['tag-b'])->put('item-b2', 'value-b2', 60);
        Cache::tags(['tag-c'])->put('counter-c', 0, 60);
        Cache::tags(['tag-c'])->increment('counter-c', 5);

        // Check results
        $valueA = $isAnyMode ? Cache::get('item-a') : Cache::tags(['tag-a'])->get('item-a');
        $valueB = $isAnyMode ? Cache::get('item-b') : Cache::tags(['tag-b'])->get('item-b');
        $valueB2 = $isAnyMode ? Cache::get('item-b2') : Cache::tags(['tag-b'])->get('item-b2');
        $valueC = $isAnyMode ? Cache::get('item-c') : Cache::tags(['tag-c'])->get('item-c');
        $counterC = $isAnyMode ? Cache::get('counter-c') : Cache::tags(['tag-c'])->get('counter-c');

        $this->assertNull($valueA);           // Flushed
        $this->assertSame('value-b', $valueB); // Untouched
        $this->assertSame('value-b2', $valueB2); // New item
        $this->assertSame('value-c', $valueC); // Untouched
        $this->assertEquals(5, $counterC);    // Incremented
    }

    // =========================================================================
    // CONCURRENT PUTMANY - BOTH MODES
    // =========================================================================

    public function testAllModeConcurrentPutMany(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertConcurrentPutMany();
    }

    public function testAnyModeConcurrentPutMany(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertConcurrentPutMany();
    }

    private function assertConcurrentPutMany(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();

        $batch1 = [];
        $batch2 = [];

        for ($i = 0; $i < 50; ++$i) {
            $batch1["batch1-{$i}"] = "value1-{$i}";
            $batch2["batch2-{$i}"] = "value2-{$i}";
        }

        Cache::tags(['batch'])->putMany($batch1, 60);
        Cache::tags(['batch'])->putMany($batch2, 60);

        // All items should exist
        foreach ($batch1 as $key => $value) {
            $cached = $isAnyMode ? Cache::get($key) : Cache::tags(['batch'])->get($key);
            $this->assertSame($value, $cached);
        }

        foreach ($batch2 as $key => $value) {
            $cached = $isAnyMode ? Cache::get($key) : Cache::tags(['batch'])->get($key);
            $this->assertSame($value, $cached);
        }
    }

    // =========================================================================
    // OVERLAPPING TAG SETS - BOTH MODES
    // =========================================================================

    public function testAllModeOverlappingTagFlushes(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertOverlappingTagFlushes();
    }

    public function testAnyModeOverlappingTagFlushes(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertOverlappingTagFlushes();
    }

    private function assertOverlappingTagFlushes(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();

        // Create items with various tag combinations
        Cache::tags(['red', 'blue'])->put('purple', 'value', 60);
        Cache::tags(['red', 'yellow'])->put('orange', 'value', 60);
        Cache::tags(['blue', 'yellow'])->put('green', 'value', 60);
        Cache::tags(['red'])->put('red-only', 'value', 60);
        Cache::tags(['blue'])->put('blue-only', 'value', 60);
        Cache::tags(['yellow'])->put('yellow-only', 'value', 60);

        // Flush red tag
        Cache::tags(['red'])->flush();

        // Check results
        $purple = $isAnyMode ? Cache::get('purple') : Cache::tags(['red', 'blue'])->get('purple');
        $orange = $isAnyMode ? Cache::get('orange') : Cache::tags(['red', 'yellow'])->get('orange');
        $redOnly = $isAnyMode ? Cache::get('red-only') : Cache::tags(['red'])->get('red-only');
        $green = $isAnyMode ? Cache::get('green') : Cache::tags(['blue', 'yellow'])->get('green');
        $blueOnly = $isAnyMode ? Cache::get('blue-only') : Cache::tags(['blue'])->get('blue-only');
        $yellowOnly = $isAnyMode ? Cache::get('yellow-only') : Cache::tags(['yellow'])->get('yellow-only');

        $this->assertNull($purple);
        $this->assertNull($orange);
        $this->assertNull($redOnly);
        $this->assertSame('value', $green);
        $this->assertSame('value', $blueOnly);
        $this->assertSame('value', $yellowOnly);
    }

    // =========================================================================
    // ATOMIC ADD OPERATIONS - BOTH MODES
    // =========================================================================

    public function testAllModeAtomicAdd(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertAtomicAdd();
    }

    public function testAnyModeAtomicAdd(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertAtomicAdd();
    }

    private function assertAtomicAdd(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        $key = 'atomic-test';

        Cache::forget($key);

        $results = [];
        for ($i = 0; $i < 5; ++$i) {
            $results[] = Cache::tags(['atomic'])->add($key, "value-{$i}", 60);
        }

        // Only first should succeed
        $this->assertTrue($results[0]);
        for ($i = 1; $i < 5; ++$i) {
            $this->assertFalse($results[$i]);
        }

        // Value should be from first add
        $value = $isAnyMode ? Cache::get($key) : Cache::tags(['atomic'])->get($key);
        $this->assertSame('value-0', $value);
    }

    // =========================================================================
    // RAPID TAG CREATION/DELETION - BOTH MODES
    // =========================================================================

    public function testAllModeRapidTagOperations(): void
    {
        $this->setTagMode(TagMode::All);

        for ($i = 0; $i < 20; ++$i) {
            $tag = "rapid-{$i}";

            Cache::tags([$tag])->put("item-{$i}", "value-{$i}", 60);
            $this->assertRedisKeyExists($this->allModeTagKey($tag));

            Cache::tags([$tag])->flush();
            $this->assertRedisKeyNotExists($this->allModeTagKey($tag));
        }
    }

    public function testAnyModeRapidTagOperations(): void
    {
        $this->setTagMode(TagMode::Any);

        for ($i = 0; $i < 20; ++$i) {
            $tag = "rapid-{$i}";

            Cache::tags([$tag])->put("item-{$i}", "value-{$i}", 60);
            $this->assertRedisKeyExists($this->anyModeTagKey($tag));

            Cache::tags([$tag])->flush();
            $this->assertRedisKeyNotExists($this->anyModeTagKey($tag));
        }
    }

    // =========================================================================
    // SWOOLE COROUTINE CONCURRENCY - ANY MODE ONLY
    // (All mode uses namespaced keys which would collide in parallel)
    // =========================================================================

    public function testAnyModeParallelIncrementsWithCoroutines(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['parallel'])->put('parallel-counter', 0, 60);

        // Limit concurrency to stay within connection pool limits
        $parallel = new Parallel(5);
        $incrementCount = 50;

        for ($i = 0; $i < $incrementCount; ++$i) {
            $parallel->add(function () {
                Cache::tags(['parallel'])->increment('parallel-counter');
            });
        }

        $parallel->wait();

        // All increments should be counted (Redis INCRBY is atomic)
        $this->assertEquals($incrementCount, Cache::get('parallel-counter'));
    }

    public function testAnyModeParallelPutsWithCoroutines(): void
    {
        $this->setTagMode(TagMode::Any);

        // Limit concurrency to stay within connection pool limits
        $parallel = new Parallel(5);
        $count = 20;

        for ($i = 0; $i < $count; ++$i) {
            $parallel->add(function () use ($i) {
                Cache::tags(['parallel-puts'])->put("parallel-key-{$i}", "value-{$i}", 60);
            });
        }

        $parallel->wait();

        // All items should exist
        for ($i = 0; $i < $count; ++$i) {
            $this->assertSame("value-{$i}", Cache::get("parallel-key-{$i}"));
        }
    }

    public function testAnyModeParallelAddsWithCoroutines(): void
    {
        $this->setTagMode(TagMode::Any);

        // Limit concurrency to stay within connection pool limits
        $parallel = new Parallel(5);
        $results = [];

        // Multiple coroutines trying to add the same key
        for ($i = 0; $i < 10; ++$i) {
            $parallel->add(function () use ($i) {
                return Cache::tags(['parallel-add'])->add('contested-key', "value-{$i}", 60);
            });
        }

        $results = $parallel->wait();

        // Exactly one should succeed
        $successCount = array_sum(array_map(fn ($r) => $r ? 1 : 0, $results));
        $this->assertEquals(1, $successCount);

        // Value should exist
        $this->assertNotNull(Cache::get('contested-key'));
    }
}
