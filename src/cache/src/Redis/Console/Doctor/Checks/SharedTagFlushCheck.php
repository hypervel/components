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
    /**
     * Get the human-readable name of this check.
     */
    public function name(): string
    {
        return 'Shared Tag Flush (Orphan Prevention)';
    }

    /**
     * Run the check and return results.
     */
    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult;

        $firstTag = $context->prefixed('tagA-' . bin2hex(random_bytes(4)));
        $secondTag = $context->prefixed('tagB-' . bin2hex(random_bytes(4)));
        $key = $context->prefixed('shared:' . bin2hex(random_bytes(4)));
        $value = 'value-' . bin2hex(random_bytes(4));

        $tags = [$firstTag, $secondTag];

        // Store item with both tags
        $context->cache->tags($tags)->put($key, $value, 60);

        // Verify item was stored
        if ($context->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $context->cache->get($key) === $value,
                'Item with shared tags is stored'
            );
            $this->testAnyMode($context, $result, $firstTag, $secondTag, $key);
        } else {
            // All mode: must use tagged get
            $result->assert(
                $context->cache->tags($tags)->get($key) === $value,
                'Item with shared tags is stored'
            );
            $this->testAllMode($context, $result, $firstTag, $secondTag, $key, $tags);
        }

        return $result;
    }

    /**
     * Test shared-tag flushing in any-tag mode.
     */
    private function testAnyMode(
        DoctorContext $context,
        CheckResult $result,
        string $firstTag,
        string $secondTag,
        string $key,
    ): void {
        // Verify in both tag hashes
        $firstTagKey = $context->tagHashKey($firstTag);
        $secondTagKey = $context->tagHashKey($secondTag);

        $result->assert(
            $context->redis->hExists($firstTagKey, $key) && $context->redis->hExists($secondTagKey, $key),
            'Key exists in both tag hashes (any mode)'
        );

        // Flush Tag A
        $context->cache->tags([$firstTag])->flush();

        $result->assert(
            $context->cache->get($key) === null,
            'Shared tag flush removes item (any mode)'
        );

        // In lazy mode (Hypervel default), orphans remain in Tag B hash
        // They will be cleaned by the scheduled prune command
        $result->assert(
            $context->redis->hExists($secondTagKey, $key),
            'Orphaned field exists in shared tag (lazy cleanup - will be cleaned by prune command)'
        );
    }

    /**
     * Test shared-tag flushing in all-tags mode.
     *
     * @param list<string> $tags
     */
    private function testAllMode(
        DoctorContext $context,
        CheckResult $result,
        string $firstTag,
        string $secondTag,
        string $key,
        array $tags,
    ): void {
        // Verify both tag ZSETs contain entries before flush
        $firstTagSetKey = $context->tagHashKey($firstTag);
        $secondTagSetKey = $context->tagHashKey($secondTag);

        $firstTagCount = $context->redis->zCard($firstTagSetKey);
        $secondTagCount = $context->redis->zCard($secondTagSetKey);

        $result->assert(
            $firstTagCount > 0 && $secondTagCount > 0,
            'Key exists in both tag ZSETs before flush (all mode)'
        );

        // Flush Tag A
        $context->cache->tags([$firstTag])->flush();

        $result->assert(
            $context->cache->tags($tags)->get($key) === null,
            'Shared tag flush removes item (all mode)'
        );

        // In all mode, the cache key is deleted when any tag is flushed
        // Orphaned entries remain in Tag B's ZSET until prune is run
        $secondTagCountAfter = $context->redis->zCard($secondTagSetKey);

        $result->assert(
            $secondTagCountAfter > 0,
            'Orphaned entry exists in shared tag ZSET (cleaned by prune command)'
        );
    }
}
