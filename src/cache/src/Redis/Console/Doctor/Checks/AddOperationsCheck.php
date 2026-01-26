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

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        // Add new key (no tags - mode agnostic)
        $addResult = $context->cache->add($context->prefixed('add:new'), 'first', 60);
        $result->assert(
            $addResult === true && $context->cache->get($context->prefixed('add:new')) === 'first',
            'add() succeeds for non-existent key'
        );

        // Try to add existing key
        $addResult = $context->cache->add($context->prefixed('add:new'), 'second', 60);
        $result->assert(
            $addResult === false && $context->cache->get($context->prefixed('add:new')) === 'first',
            'add() fails for existing key (value unchanged)'
        );

        // Add with tags
        $addTag = $context->prefixed('unique');
        $addKey = $context->prefixed('add:tagged');
        $addResult = $context->cache->tags([$addTag])->add($addKey, 'value', 60);
        $result->assert(
            $addResult === true,
            'add() with tags succeeds for non-existent key'
        );

        // Verify the value was actually stored and is retrievable
        if ($context->isAnyMode()) {
            $storedValue = $context->cache->get($addKey);
            $result->assert(
                $storedValue === 'value',
                'add() with tags: value retrievable via direct get (any mode)'
            );
        } else {
            $storedValue = $context->cache->tags([$addTag])->get($addKey);
            $result->assert(
                $storedValue === 'value',
                'add() with tags: value retrievable via tagged get (all mode)'
            );

            // Verify ZSET entry exists
            $tagSetKey = $context->tagHashKey($addTag);
            $entryCount = $context->redis->zCard($tagSetKey);
            $result->assert(
                $entryCount > 0,
                'add() with tags: ZSET entry created (all mode)'
            );
        }

        // Try to add existing key with tags
        $addResult = $context->cache->tags([$addTag])->add($addKey, 'new value', 60);
        $result->assert(
            $addResult === false,
            'add() with tags fails for existing key'
        );

        // Verify value unchanged after failed add
        if ($context->isAnyMode()) {
            $unchangedValue = $context->cache->get($addKey);
        } else {
            $unchangedValue = $context->cache->tags([$addTag])->get($addKey);
        }
        $result->assert(
            $unchangedValue === 'value',
            'add() with tags: value unchanged after failed add'
        );

        return $result;
    }
}
