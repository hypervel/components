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

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Increment without tags
        $ctx->cache->put($ctx->prefixed('incr:counter1'), 0, 60);
        $incrementResult = $ctx->cache->increment($ctx->prefixed('incr:counter1'), 5);
        $result->assert(
            $incrementResult === 5 && $ctx->cache->get($ctx->prefixed('incr:counter1')) === '5',
            'increment() increases value (returns string)'
        );

        // Decrement without tags
        $decrementResult = $ctx->cache->decrement($ctx->prefixed('incr:counter1'), 3);
        $result->assert(
            $decrementResult === 2 && $ctx->cache->get($ctx->prefixed('incr:counter1')) === '2',
            'decrement() decreases value (returns string)'
        );

        // Increment with tags
        $counterTag = $ctx->prefixed('counters');
        $taggedKey = $ctx->prefixed('incr:tagged');
        $ctx->cache->tags([$counterTag])->put($taggedKey, 10, 60);
        $taggedResult = $ctx->cache->tags([$counterTag])->increment($taggedKey, 15);

        if ($ctx->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $taggedResult === 25 && $ctx->cache->get($taggedKey) === '25',
                'increment() works with tags'
            );
        } else {
            // All mode: must use tagged get
            $result->assert(
                $taggedResult === 25 && $ctx->cache->tags([$counterTag])->get($taggedKey) === '25',
                'increment() works with tags'
            );
        }

        // Test increment on non-existent key (creates it)
        $ctx->cache->forget($ctx->prefixed('incr:new'));
        $newResult = $ctx->cache->tags([$ctx->prefixed('counters')])->increment($ctx->prefixed('incr:new'), 1);
        $result->assert(
            $newResult === 1,
            'increment() creates non-existent key'
        );

        if ($ctx->isAnyMode()) {
            $this->testAnyModeHashTtl($ctx, $result);
        } else {
            $this->testAllMode($ctx, $result);
        }

        return $result;
    }

    private function testAnyModeHashTtl(DoctorContext $ctx, CheckResult $result): void
    {
        // Verify hash field has no expiration for non-TTL key
        $ttl = $ctx->redis->httl($ctx->tagHashKey($ctx->prefixed('counters')), [$ctx->prefixed('incr:new')]);
        $result->assert(
            $ttl[0] === -1,
            'Tag entry for non-TTL key has no expiration (any mode)'
        );
    }

    private function testAllMode(DoctorContext $ctx, CheckResult $result): void
    {
        // Verify ZSET entry exists for incremented key
        $counterTag = $ctx->prefixed('counters');
        $incrKey = $ctx->prefixed('incr:new');

        $tagSetKey = $ctx->tagHashKey($counterTag);

        // Compute namespaced key using central source of truth
        $namespacedKey = $ctx->namespacedKey([$counterTag], $incrKey);

        // Verify ZSET entry exists
        // Note: increment on non-existent key creates with no TTL, so score should be -1
        $score = $ctx->redis->zScore($tagSetKey, $namespacedKey);
        $result->assert(
            $score !== false,
            'ZSET entry exists for incremented key (all mode)'
        );
    }
}
