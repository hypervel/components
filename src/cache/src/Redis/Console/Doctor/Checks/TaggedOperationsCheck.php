<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use BadMethodCallException;
use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests tagged cache operations: tagged put, get, flush.
 *
 * Behavior differs between tagging modes:
 * - Any mode: get() on tagged cache throws BadMethodCallException
 * - All mode: get() on tagged cache works normally
 */
final class TaggedOperationsCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Tagged Cache Operations';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Single tag put
        $tag = $ctx->prefixed('products');
        $key = $ctx->prefixed('tag:product1');
        $ctx->cache->tags([$tag])->put($key, 'Product 1', 60);

        if ($ctx->isAnyMode()) {
            // Any mode: key is stored without namespace modification
            // Can be retrieved directly without tags
            $result->assert(
                $ctx->cache->get($key) === 'Product 1',
                'Tagged item can be retrieved without tags (direct get)'
            );
            $this->testAnyMode($ctx, $result, $tag, $key);
        } else {
            // All mode: key is namespaced with sha1 of tags
            // Direct get without tags will NOT find the item
            $result->assert(
                $ctx->cache->get($key) === null,
                'Tagged item NOT retrievable without tags (namespace differs)'
            );
            $this->testAllMode($ctx, $result, $tag, $key);
        }

        // Tag flush (common to both modes)
        $ctx->cache->tags([$tag])->flush();

        if ($ctx->isAnyMode()) {
            $result->assert(
                $ctx->cache->get($key) === null,
                'flush() removes tagged items'
            );
        } else {
            // In all mode, use tagged get to verify flush worked
            $result->assert(
                $ctx->cache->tags([$tag])->get($key) === null,
                'flush() removes tagged items'
            );
        }

        return $result;
    }

    private function testAnyMode(DoctorContext $ctx, CheckResult $result, string $tag, string $key): void
    {
        // Verify hash structure exists
        $tagKey = $ctx->tagHashKey($tag);
        $result->assert(
            $ctx->redis->hExists($tagKey, $key) === true,
            'Tag hash contains the cache key (any mode)'
        );

        // Verify get() on tagged cache throws
        $threw = false;
        try {
            $ctx->cache->tags([$tag])->get($key);
        } catch (BadMethodCallException) {
            $threw = true;
        }
        $result->assert(
            $threw,
            'Tagged get() throws BadMethodCallException (any mode)'
        );
    }

    private function testAllMode(DoctorContext $ctx, CheckResult $result, string $tag, string $key): void
    {
        // In all mode, get() on tagged cache works
        $value = $ctx->cache->tags([$tag])->get($key);
        $result->assert(
            $value === 'Product 1',
            'Tagged get() returns value (all mode)'
        );

        // Verify tag sorted set structure exists
        // Tag key format: {prefix}tag:{tagName}:entries
        $tagSetKey = $ctx->tagHashKey($tag);
        $members = $ctx->redis->zRange($tagSetKey, 0, -1);
        $result->assert(
            is_array($members) && count($members) > 0,
            'Tag ZSET contains entries (all mode)'
        );
    }
}
