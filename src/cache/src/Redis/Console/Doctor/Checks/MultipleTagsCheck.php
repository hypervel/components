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

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        $tags = [
            $ctx->prefixed('posts'),
            $ctx->prefixed('featured'),
            $ctx->prefixed('user:123'),
        ];
        $key = $ctx->prefixed('multi:post1');

        // Store with multiple tags
        $ctx->cache->tags($tags)->put($key, 'Featured Post', 60);

        // Verify item was stored
        if ($ctx->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $ctx->cache->get($key) === 'Featured Post',
                'Item with multiple tags is stored'
            );
            $this->testAnyMode($ctx, $result, $tags, $key);
        } else {
            // All mode: must use tagged get
            $result->assert(
                $ctx->cache->tags($tags)->get($key) === 'Featured Post',
                'Item with multiple tags is stored'
            );
            $this->testAllMode($ctx, $result, $tags, $key);
        }

        return $result;
    }

    /**
     * @param array<string> $tags
     */
    private function testAnyMode(DoctorContext $ctx, CheckResult $result, array $tags, string $key): void
    {
        // Verify in all tag hashes
        $result->assert(
            $ctx->redis->hExists($ctx->tagHashKey($tags[0]), $key) === true
            && $ctx->redis->hExists($ctx->tagHashKey($tags[1]), $key) === true
            && $ctx->redis->hExists($ctx->tagHashKey($tags[2]), $key) === true,
            'Item appears in all tag hashes (any mode)'
        );

        // Flush by one tag (any behavior - removes item)
        $ctx->cache->tags([$tags[1]])->flush();

        $result->assert(
            $ctx->cache->get($key) === null,
            'Flushing ANY tag removes the item (any behavior)'
        );

        $result->assert(
            $ctx->redis->exists($ctx->tagHashKey($tags[1])) === 0,
            'Flushed tag hash is deleted (any mode)'
        );
    }

    /**
     * @param array<string> $tags
     */
    private function testAllMode(DoctorContext $ctx, CheckResult $result, array $tags, string $key): void
    {
        // Verify all tag ZSETs contain an entry
        $postsTagKey = $ctx->tagHashKey($tags[0]);
        $featuredTagKey = $ctx->tagHashKey($tags[1]);
        $userTagKey = $ctx->tagHashKey($tags[2]);

        $postsCount = $ctx->redis->zCard($postsTagKey);
        $featuredCount = $ctx->redis->zCard($featuredTagKey);
        $userCount = $ctx->redis->zCard($userTagKey);

        $result->assert(
            $postsCount > 0 && $featuredCount > 0 && $userCount > 0,
            'Item appears in all tag ZSETs (all mode)'
        );

        // Flush by one tag - in all mode, this removes items tracked in that tag's ZSET
        $ctx->cache->tags([$tags[1]])->flush();

        $result->assert(
            $ctx->cache->tags($tags)->get($key) === null,
            'Flushing tag removes items with that tag (all mode)'
        );

        // Test tag order matters in all mode
        $orderKey = $ctx->prefixed('multi:order-test');
        $ctx->cache->tags([$ctx->prefixed('alpha'), $ctx->prefixed('beta')])->put($orderKey, 'ordered', 60);

        // Same order should retrieve
        $sameOrder = $ctx->cache->tags([$ctx->prefixed('alpha'), $ctx->prefixed('beta')])->get($orderKey);

        // Different order creates different namespace - should NOT retrieve
        $diffOrder = $ctx->cache->tags([$ctx->prefixed('beta'), $ctx->prefixed('alpha')])->get($orderKey);

        $result->assert(
            $sameOrder === 'ordered' && $diffOrder === null,
            'Tag order matters - different order creates different namespace'
        );
    }
}
