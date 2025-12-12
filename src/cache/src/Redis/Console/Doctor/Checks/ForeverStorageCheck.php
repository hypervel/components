<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests forever() storage (no expiration).
 *
 * Basic forever storage is mode-agnostic, but hash field TTL verification
 * is any mode specific.
 */
final class ForeverStorageCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Forever Storage (No Expiration)';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Forever without tags
        $ctx->cache->forever($ctx->prefixed('forever:key1'), 'permanent');
        $ttl = $ctx->redis->ttl($ctx->cachePrefix . $ctx->prefixed('forever:key1'));
        $result->assert(
            $ttl === -1,
            'forever() stores without expiration'
        );

        // Forever with tags
        $foreverTag = $ctx->prefixed('permanent');
        $foreverKey = $ctx->prefixed('forever:tagged');
        $ctx->cache->tags([$foreverTag])->forever($foreverKey, 'also permanent');

        if ($ctx->isAnyMode()) {
            // Any mode: key is stored without namespace modification
            $keyTtl = $ctx->redis->ttl($ctx->cachePrefix . $foreverKey);
            $result->assert(
                $keyTtl === -1,
                'forever() with tags: key has no expiration'
            );
            $this->testAnyModeHashTtl($ctx, $result, $foreverTag, $foreverKey);
        } else {
            // All mode: key is namespaced with sha1 of tag IDs
            $namespacedKey = $ctx->namespacedKey([$foreverTag], $foreverKey);
            $keyTtl = $ctx->redis->ttl($ctx->cachePrefix . $namespacedKey);
            $result->assert(
                $keyTtl === -1,
                'forever() with tags: key has no expiration'
            );
            $this->testAllMode($ctx, $result, $foreverTag, $foreverKey, $namespacedKey);
        }

        return $result;
    }

    private function testAnyModeHashTtl(DoctorContext $ctx, CheckResult $result, string $tag, string $key): void
    {
        // Verify hash field also has no expiration
        $fieldTtl = $ctx->redis->httl($ctx->tagHashKey($tag), [$key]);
        $result->assert(
            $fieldTtl[0] === -1,
            'forever() with tags: hash field has no expiration (any mode)'
        );
    }

    private function testAllMode(
        DoctorContext $ctx,
        CheckResult $result,
        string $tag,
        string $key,
        string $namespacedKey,
    ): void {
        // Verify sorted set score is -1 for forever items
        $tagSetKey = $ctx->tagHashKey($tag);
        $score = $ctx->redis->zScore($tagSetKey, $namespacedKey);

        $result->assert(
            $score === -1.0,
            'forever() with tags: ZSET entry has score -1 (all mode)'
        );
    }
}
