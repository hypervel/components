<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests bulk operations: putMany() and many().
 *
 * Basic operations are mode-agnostic, but tag hash verification is any mode specific.
 */
final class BulkOperationsCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Bulk Operations (putMany/many)';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        // putMany without tags
        $context->cache->putMany([
            $context->prefixed('bulk:1') => 'value1',
            $context->prefixed('bulk:2') => 'value2',
            $context->prefixed('bulk:3') => 'value3',
        ], 60);

        $result->assert(
            $context->cache->get($context->prefixed('bulk:1')) === 'value1'
            && $context->cache->get($context->prefixed('bulk:2')) === 'value2'
            && $context->cache->get($context->prefixed('bulk:3')) === 'value3',
            'putMany() stores multiple items'
        );

        // many()
        $values = $context->cache->many([
            $context->prefixed('bulk:1'),
            $context->prefixed('bulk:2'),
            $context->prefixed('bulk:nonexistent'),
        ]);
        $result->assert(
            $values[$context->prefixed('bulk:1')] === 'value1'
            && $values[$context->prefixed('bulk:2')] === 'value2'
            && $values[$context->prefixed('bulk:nonexistent')] === null,
            'many() retrieves multiple items (null for missing)'
        );

        // putMany with tags
        $bulkTag = $context->prefixed('bulk');
        $taggedKey1 = $context->prefixed('bulk:tagged1');
        $taggedKey2 = $context->prefixed('bulk:tagged2');

        $context->cache->tags([$bulkTag])->putMany([
            $taggedKey1 => 'tagged1',
            $taggedKey2 => 'tagged2',
        ], 60);

        if ($context->isAnyMode()) {
            $result->assert(
                $context->redis->hExists($context->tagHashKey($bulkTag), $taggedKey1) === true
                && $context->redis->hExists($context->tagHashKey($bulkTag), $taggedKey2) === true,
                'putMany() with tags adds all items to tag hash (any mode)'
            );
        } else {
            // Verify all mode sorted set contains entries
            $tagSetKey = $context->tagHashKey($bulkTag);
            $entryCount = $context->redis->zCard($tagSetKey);
            $result->assert(
                $entryCount >= 2,
                'putMany() with tags adds entries to tag ZSET (all mode)'
            );
        }

        // Flush putMany tags
        $context->cache->tags([$bulkTag])->flush();

        if ($context->isAnyMode()) {
            $result->assert(
                $context->cache->get($taggedKey1) === null && $context->cache->get($taggedKey2) === null,
                'flush() removes items added via putMany()'
            );
        } else {
            $result->assert(
                $context->cache->tags([$bulkTag])->get($taggedKey1) === null
                && $context->cache->tags([$bulkTag])->get($taggedKey2) === null,
                'flush() removes items added via putMany()'
            );
        }

        return $result;
    }
}
