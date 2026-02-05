<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests increment and decrement operations with and without tags.
 *
 * Basic increment/decrement is mode-agnostic, but hash field TTL verification
 * is any mode specific.
 */
final class IncrementDecrementCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Increment/Decrement Operations';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        // Increment without tags
        $context->cache->put($context->prefixed('incr:counter1'), 0, 60);
        $incrementResult = $context->cache->increment($context->prefixed('incr:counter1'), 5);
        $result->assert(
            $incrementResult === 5 && $context->cache->get($context->prefixed('incr:counter1')) === '5',
            'increment() increases value (returns string)'
        );

        // Decrement without tags
        $decrementResult = $context->cache->decrement($context->prefixed('incr:counter1'), 3);
        $result->assert(
            $decrementResult === 2 && $context->cache->get($context->prefixed('incr:counter1')) === '2',
            'decrement() decreases value (returns string)'
        );

        // Increment with tags
        $counterTag = $context->prefixed('counters');
        $taggedKey = $context->prefixed('incr:tagged');
        $context->cache->tags([$counterTag])->put($taggedKey, 10, 60);
        $taggedResult = $context->cache->tags([$counterTag])->increment($taggedKey, 15);

        if ($context->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $taggedResult === 25 && $context->cache->get($taggedKey) === '25',
                'increment() works with tags'
            );
        } else {
            // All mode: must use tagged get
            $result->assert(
                $taggedResult === 25 && $context->cache->tags([$counterTag])->get($taggedKey) === '25',
                'increment() works with tags'
            );
        }

        // Test increment on non-existent key (creates it)
        $context->cache->forget($context->prefixed('incr:new'));
        $newResult = $context->cache->tags([$context->prefixed('counters')])->increment($context->prefixed('incr:new'), 1);
        $result->assert(
            $newResult === 1,
            'increment() creates non-existent key'
        );

        if ($context->isAnyMode()) {
            $this->testAnyModeHashTtl($context, $result);
        } else {
            $this->testAllMode($context, $result);
        }

        return $result;
    }

    private function testAnyModeHashTtl(DoctorContext $context, CheckResult $result): void
    {
        // Verify hash field has no expiration for non-TTL key
        $ttl = $context->redis->httl($context->tagHashKey($context->prefixed('counters')), [$context->prefixed('incr:new')]);
        $result->assert(
            $ttl[0] === -1,
            'Tag entry for non-TTL key has no expiration (any mode)'
        );
    }

    private function testAllMode(DoctorContext $context, CheckResult $result): void
    {
        // Verify ZSET entry exists for incremented key
        $counterTag = $context->prefixed('counters');
        $incrKey = $context->prefixed('incr:new');

        $tagSetKey = $context->tagHashKey($counterTag);

        // Compute namespaced key using central source of truth
        $namespacedKey = $context->namespacedKey([$counterTag], $incrKey);

        // Verify ZSET entry exists
        // Note: increment on non-existent key creates with no TTL, so score should be -1
        $score = $context->redis->zScore($tagSetKey, $namespacedKey);
        $result->assert(
            $score !== false,
            'ZSET entry exists for incremented key (all mode)'
        );
    }
}
