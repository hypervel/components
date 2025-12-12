<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Verifies Redis hash structures are created correctly.
 *
 * This check is any mode only (all mode uses sorted sets instead).
 */
final class HashStructuresCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Redis Hash Structures Verification';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        if ($ctx->isAllMode()) {
            $result->assert(
                true,
                'Hash structures check skipped (all mode uses sorted sets)'
            );

            return $result;
        }

        // Create tagged item
        $ctx->cache->tags([$ctx->prefixed('verify')])->put($ctx->prefixed('hash:item'), 'value', 120);

        $tagKey = $ctx->tagHashKey($ctx->prefixed('verify'));

        // Verify hash exists
        $result->assert(
            $ctx->redis->exists($tagKey) === 1,
            'Tag hash is created'
        );

        // Verify field exists
        $result->assert(
            $ctx->redis->hExists($tagKey, $ctx->prefixed('hash:item')) === true,
            'Cache key is added as hash field'
        );

        // Verify field value
        $value = $ctx->redis->hGet($tagKey, $ctx->prefixed('hash:item'));
        $result->assert(
            $value === '1',
            'Hash field value is "1" (minimal metadata)'
        );

        // Verify field has expiration
        $ttl = $ctx->redis->httl($tagKey, [$ctx->prefixed('hash:item')]);
        $result->assert(
            $ttl[0] > 0 && $ttl[0] <= 120,
            'Hash field has expiration matching cache TTL'
        );

        // Verify cache key itself exists
        $result->assert(
            $ctx->redis->exists($ctx->cachePrefix . $ctx->prefixed('hash:item')) === 1,
            'Cache key exists in Redis'
        );

        // Verify cache key TTL
        $keyTtl = $ctx->redis->ttl($ctx->cachePrefix . $ctx->prefixed('hash:item'));
        $result->assert(
            $keyTtl > 0 && $keyTtl <= 120,
            'Cache key has correct TTL'
        );

        return $result;
    }
}
