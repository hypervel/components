<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests shared tag flush behavior and orphan handling.
 *
 * When an item has multiple tags and one tag is flushed,
 * orphaned references may remain in other tags (lazy cleanup).
 */
final class SharedTagFlushCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Shared Tag Flush (Orphan Prevention)';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        $tagA = $ctx->prefixed('tagA-' . bin2hex(random_bytes(4)));
        $tagB = $ctx->prefixed('tagB-' . bin2hex(random_bytes(4)));
        $key = $ctx->prefixed('shared:' . bin2hex(random_bytes(4)));
        $value = 'value-' . bin2hex(random_bytes(4));

        $tags = [$tagA, $tagB];

        // Store item with both tags
        $ctx->cache->tags($tags)->put($key, $value, 60);

        // Verify item was stored
        if ($ctx->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $ctx->cache->get($key) === $value,
                'Item with shared tags is stored'
            );
            $this->testAnyMode($ctx, $result, $tagA, $tagB, $key);
        } else {
            // All mode: must use tagged get
            $result->assert(
                $ctx->cache->tags($tags)->get($key) === $value,
                'Item with shared tags is stored'
            );
            $this->testAllMode($ctx, $result, $tagA, $tagB, $key, $tags);
        }

        return $result;
    }

    private function testAnyMode(
        DoctorContext $ctx,
        CheckResult $result,
        string $tagA,
        string $tagB,
        string $key,
    ): void {
        // Verify in both tag hashes
        $tagAKey = $ctx->tagHashKey($tagA);
        $tagBKey = $ctx->tagHashKey($tagB);

        $result->assert(
            $ctx->redis->hExists($tagAKey, $key) && $ctx->redis->hExists($tagBKey, $key),
            'Key exists in both tag hashes (any mode)'
        );

        // Flush Tag A
        $ctx->cache->tags([$tagA])->flush();

        $result->assert(
            $ctx->cache->get($key) === null,
            'Shared tag flush removes item (any mode)'
        );

        // In lazy mode (Hypervel default), orphans remain in Tag B hash
        // They will be cleaned by the scheduled prune command
        $result->assert(
            $ctx->redis->hExists($tagBKey, $key),
            'Orphaned field exists in shared tag (lazy cleanup - will be cleaned by prune command)'
        );
    }

    /**
     * @param array<string> $tags
     */
    private function testAllMode(
        DoctorContext $ctx,
        CheckResult $result,
        string $tagA,
        string $tagB,
        string $key,
        array $tags,
    ): void {
        // Verify both tag ZSETs contain entries before flush
        $tagASetKey = $ctx->tagHashKey($tagA);
        $tagBSetKey = $ctx->tagHashKey($tagB);

        $tagACount = $ctx->redis->zCard($tagASetKey);
        $tagBCount = $ctx->redis->zCard($tagBSetKey);

        $result->assert(
            $tagACount > 0 && $tagBCount > 0,
            'Key exists in both tag ZSETs before flush (all mode)'
        );

        // Flush Tag A
        $ctx->cache->tags([$tagA])->flush();

        $result->assert(
            $ctx->cache->tags($tags)->get($key) === null,
            'Shared tag flush removes item (all mode)'
        );

        // In all mode, the cache key is deleted when any tag is flushed
        // Orphaned entries remain in Tag B's ZSET until prune is run
        $tagBCountAfter = $ctx->redis->zCard($tagBSetKey);

        $result->assert(
            $tagBCountAfter > 0,
            'Orphaned entry exists in shared tag ZSET (cleaned by prune command)'
        );
    }
}
