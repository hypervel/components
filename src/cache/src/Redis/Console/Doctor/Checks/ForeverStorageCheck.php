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

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        // Forever without tags
        $context->cache->forever($context->prefixed('forever:key1'), 'permanent');
        $ttl = $context->redis->ttl($context->cachePrefix . $context->prefixed('forever:key1'));
        $result->assert(
            $ttl === -1,
            'forever() stores without expiration'
        );

        // Forever with tags
        $foreverTag = $context->prefixed('permanent');
        $foreverKey = $context->prefixed('forever:tagged');
        $context->cache->tags([$foreverTag])->forever($foreverKey, 'also permanent');

        if ($context->isAnyMode()) {
            // Any mode: key is stored without namespace modification
            $keyTtl = $context->redis->ttl($context->cachePrefix . $foreverKey);
            $result->assert(
                $keyTtl === -1,
                'forever() with tags: key has no expiration'
            );
            $this->testAnyModeHashTtl($context, $result, $foreverTag, $foreverKey);
        } else {
            // All mode: key is namespaced with sha1 of tag IDs
            $namespacedKey = $context->namespacedKey([$foreverTag], $foreverKey);
            $keyTtl = $context->redis->ttl($context->cachePrefix . $namespacedKey);
            $result->assert(
                $keyTtl === -1,
                'forever() with tags: key has no expiration'
            );
            $this->testAllMode($context, $result, $foreverTag, $foreverKey, $namespacedKey);
        }

        return $result;
    }

    private function testAnyModeHashTtl(DoctorContext $context, CheckResult $result, string $tag, string $key): void
    {
        // Verify hash field also has no expiration
        $fieldTtl = $context->redis->httl($context->tagHashKey($tag), [$key]);
        $result->assert(
            $fieldTtl[0] === -1,
            'forever() with tags: hash field has no expiration (any mode)'
        );
    }

    private function testAllMode(
        DoctorContext $context,
        CheckResult $result,
        string $tag,
        string $key,
        string $namespacedKey,
    ): void {
        // Verify sorted set score is -1 for forever items
        $tagSetKey = $context->tagHashKey($tag);
        $score = $context->redis->zScore($tagSetKey, $namespacedKey);

        $result->assert(
            $score === -1.0,
            'forever() with tags: ZSET entry has score -1 (all mode)'
        );
    }
}
