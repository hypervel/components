<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests operations with multiple tags.
 *
 * Flush behavior differs between modes:
 * - Any mode: Flushing ANY tag removes the item
 * - All mode: Flushing requires ALL tags to match
 */
final class MultipleTagsCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Multiple Tag Operations';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        $tags = [
            $context->prefixed('posts'),
            $context->prefixed('featured'),
            $context->prefixed('user:123'),
        ];
        $key = $context->prefixed('multi:post1');

        // Store with multiple tags
        $context->cache->tags($tags)->put($key, 'Featured Post', 60);

        // Verify item was stored
        if ($context->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $context->cache->get($key) === 'Featured Post',
                'Item with multiple tags is stored'
            );
            $this->testAnyMode($context, $result, $tags, $key);
        } else {
            // All mode: must use tagged get
            $result->assert(
                $context->cache->tags($tags)->get($key) === 'Featured Post',
                'Item with multiple tags is stored'
            );
            $this->testAllMode($context, $result, $tags, $key);
        }

        return $result;
    }

    /**
     * @param array<string> $tags
     */
    private function testAnyMode(DoctorContext $context, CheckResult $result, array $tags, string $key): void
    {
        // Verify in all tag hashes
        $result->assert(
            $context->redis->hExists($context->tagHashKey($tags[0]), $key) === true
            && $context->redis->hExists($context->tagHashKey($tags[1]), $key) === true
            && $context->redis->hExists($context->tagHashKey($tags[2]), $key) === true,
            'Item appears in all tag hashes (any mode)'
        );

        // Flush by one tag (any behavior - removes item)
        $context->cache->tags([$tags[1]])->flush();

        $result->assert(
            $context->cache->get($key) === null,
            'Flushing ANY tag removes the item (any behavior)'
        );

        $result->assert(
            $context->redis->exists($context->tagHashKey($tags[1])) === 0,
            'Flushed tag hash is deleted (any mode)'
        );
    }

    /**
     * @param array<string> $tags
     */
    private function testAllMode(DoctorContext $context, CheckResult $result, array $tags, string $key): void
    {
        // Verify all tag ZSETs contain an entry
        $postsTagKey = $context->tagHashKey($tags[0]);
        $featuredTagKey = $context->tagHashKey($tags[1]);
        $userTagKey = $context->tagHashKey($tags[2]);

        $postsCount = $context->redis->zCard($postsTagKey);
        $featuredCount = $context->redis->zCard($featuredTagKey);
        $userCount = $context->redis->zCard($userTagKey);

        $result->assert(
            $postsCount > 0 && $featuredCount > 0 && $userCount > 0,
            'Item appears in all tag ZSETs (all mode)'
        );

        // Flush by one tag - in all mode, this removes items tracked in that tag's ZSET
        $context->cache->tags([$tags[1]])->flush();

        $result->assert(
            $context->cache->tags($tags)->get($key) === null,
            'Flushing tag removes items with that tag (all mode)'
        );

        // Test tag order matters in all mode
        $orderKey = $context->prefixed('multi:order-test');
        $context->cache->tags([$context->prefixed('alpha'), $context->prefixed('beta')])->put($orderKey, 'ordered', 60);

        // Same order should retrieve
        $sameOrder = $context->cache->tags([$context->prefixed('alpha'), $context->prefixed('beta')])->get($orderKey);

        // Different order creates different namespace - should NOT retrieve
        $diffOrder = $context->cache->tags([$context->prefixed('beta'), $context->prefixed('alpha')])->get($orderKey);

        $result->assert(
            $sameOrder === 'ordered' && $diffOrder === null,
            'Tag order matters - different order creates different namespace'
        );
    }
}
