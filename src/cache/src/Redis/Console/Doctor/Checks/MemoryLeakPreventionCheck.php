<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests memory leak prevention through tag reference expiration.
 *
 * Any mode: Hash fields auto-expire via HEXPIRE.
 * All mode: Sorted set entries cleaned via ZREMRANGEBYSCORE.
 *
 * Hypervel only supports lazy cleanup mode (orphans cleaned by scheduled command).
 */
final class MemoryLeakPreventionCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Memory Leak Prevention';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        if ($ctx->isAnyMode()) {
            $this->testAnyMode($ctx, $result);
        } else {
            $this->testAllMode($ctx, $result);
        }

        return $result;
    }

    private function testAnyMode(DoctorContext $ctx, CheckResult $result): void
    {
        // Create item with short TTL
        $ctx->cache->tags([$ctx->prefixed('leak-test')])->put($ctx->prefixed('leak:short'), 'value', 3);

        $tagKey = $ctx->tagHashKey($ctx->prefixed('leak-test'));

        // Verify field has expiration
        $ttl = $ctx->redis->httl($tagKey, [$ctx->prefixed('leak:short')]);
        $result->assert(
            $ttl[0] > 0 && $ttl[0] <= 3,
            'Hash field has TTL set (will auto-expire)'
        );

        // Test lazy cleanup after flush
        $ctx->cache->tags([$ctx->prefixed('alpha'), $ctx->prefixed('beta')])->put($ctx->prefixed('leak:shared'), 'value', 60);

        // Flush one tag
        $ctx->cache->tags([$ctx->prefixed('alpha')])->flush();

        // Alpha hash should be deleted
        $result->assert(
            $ctx->redis->exists($ctx->tagHashKey($ctx->prefixed('alpha'))) === 0,
            'Flushed tag hash is deleted'
        );

        // Hypervel uses lazy cleanup mode - orphans remain until prune command runs
        $result->assert(
            $ctx->redis->hexists($ctx->tagHashKey($ctx->prefixed('beta')), $ctx->prefixed('leak:shared')),
            'Orphaned field exists in shared tag hash (lazy cleanup - will be cleaned by prune command)'
        );
    }

    private function testAllMode(DoctorContext $ctx, CheckResult $result): void
    {
        // Create item with future TTL
        $leakTag = $ctx->prefixed('leak-test');
        $leakKey = $ctx->prefixed('leak:short');
        $ctx->cache->tags([$leakTag])->put($leakKey, 'value', 60);

        $tagSetKey = $ctx->tagHashKey($leakTag);

        // Compute the namespaced key using central source of truth
        $namespacedKey = $ctx->namespacedKey([$leakTag], $leakKey);

        // Verify ZSET entry exists with future timestamp score
        $score = $ctx->redis->zScore($tagSetKey, $namespacedKey);
        $result->assert(
            $score !== false && $score > time(),
            'ZSET entry has future timestamp score (will be cleaned when expired)'
        );

        // Test lazy cleanup after flush
        $alphaTag = $ctx->prefixed('alpha');
        $betaTag = $ctx->prefixed('beta');
        $sharedKey = $ctx->prefixed('leak:shared');
        $ctx->cache->tags([$alphaTag, $betaTag])->put($sharedKey, 'value', 60);

        // Compute namespaced key for shared item using central source of truth
        $sharedNamespacedKey = $ctx->namespacedKey([$alphaTag, $betaTag], $sharedKey);

        // Flush one tag
        $ctx->cache->tags([$alphaTag])->flush();

        // Alpha ZSET should be deleted
        $alphaSetKey = $ctx->tagHashKey($alphaTag);
        $result->assert(
            $ctx->redis->exists($alphaSetKey) === 0,
            'Flushed tag ZSET is deleted'
        );

        // All mode uses lazy cleanup - orphaned entry remains in beta ZSET until prune command runs
        $betaSetKey = $ctx->tagHashKey($betaTag);
        $orphanScore = $ctx->redis->zScore($betaSetKey, $sharedNamespacedKey);
        $result->assert(
            $orphanScore !== false,
            'Orphaned entry exists in shared tag ZSET (lazy cleanup - will be cleaned by prune command)'
        );
    }
}
