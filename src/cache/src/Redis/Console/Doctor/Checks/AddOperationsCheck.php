<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests add() operation (only stores if key doesn't exist).
 *
 * Basic add() is mode-agnostic, but retrieval and tag structure verification
 * differs between modes.
 */
final class AddOperationsCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Add Operations (Only If Not Exists)';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Add new key (no tags - mode agnostic)
        $addResult = $ctx->cache->add($ctx->prefixed('add:new'), 'first', 60);
        $result->assert(
            $addResult === true && $ctx->cache->get($ctx->prefixed('add:new')) === 'first',
            'add() succeeds for non-existent key'
        );

        // Try to add existing key
        $addResult = $ctx->cache->add($ctx->prefixed('add:new'), 'second', 60);
        $result->assert(
            $addResult === false && $ctx->cache->get($ctx->prefixed('add:new')) === 'first',
            'add() fails for existing key (value unchanged)'
        );

        // Add with tags
        $addTag = $ctx->prefixed('unique');
        $addKey = $ctx->prefixed('add:tagged');
        $addResult = $ctx->cache->tags([$addTag])->add($addKey, 'value', 60);
        $result->assert(
            $addResult === true,
            'add() with tags succeeds for non-existent key'
        );

        // Verify the value was actually stored and is retrievable
        if ($ctx->isAnyMode()) {
            $storedValue = $ctx->cache->get($addKey);
            $result->assert(
                $storedValue === 'value',
                'add() with tags: value retrievable via direct get (any mode)'
            );
        } else {
            $storedValue = $ctx->cache->tags([$addTag])->get($addKey);
            $result->assert(
                $storedValue === 'value',
                'add() with tags: value retrievable via tagged get (all mode)'
            );

            // Verify ZSET entry exists
            $tagSetKey = $ctx->tagHashKey($addTag);
            $entryCount = $ctx->redis->zCard($tagSetKey);
            $result->assert(
                $entryCount > 0,
                'add() with tags: ZSET entry created (all mode)'
            );
        }

        // Try to add existing key with tags
        $addResult = $ctx->cache->tags([$addTag])->add($addKey, 'new value', 60);
        $result->assert(
            $addResult === false,
            'add() with tags fails for existing key'
        );

        // Verify value unchanged after failed add
        if ($ctx->isAnyMode()) {
            $unchangedValue = $ctx->cache->get($addKey);
        } else {
            $unchangedValue = $ctx->cache->tags([$addTag])->get($addKey);
        }
        $result->assert(
            $unchangedValue === 'value',
            'add() with tags: value unchanged after failed add'
        );

        return $result;
    }
}
