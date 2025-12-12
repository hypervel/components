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

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // putMany without tags
        $ctx->cache->putMany([
            $ctx->prefixed('bulk:1') => 'value1',
            $ctx->prefixed('bulk:2') => 'value2',
            $ctx->prefixed('bulk:3') => 'value3',
        ], 60);

        $result->assert(
            $ctx->cache->get($ctx->prefixed('bulk:1')) === 'value1'
            && $ctx->cache->get($ctx->prefixed('bulk:2')) === 'value2'
            && $ctx->cache->get($ctx->prefixed('bulk:3')) === 'value3',
            'putMany() stores multiple items'
        );

        // many()
        $values = $ctx->cache->many([
            $ctx->prefixed('bulk:1'),
            $ctx->prefixed('bulk:2'),
            $ctx->prefixed('bulk:nonexistent'),
        ]);
        $result->assert(
            $values[$ctx->prefixed('bulk:1')] === 'value1'
            && $values[$ctx->prefixed('bulk:2')] === 'value2'
            && $values[$ctx->prefixed('bulk:nonexistent')] === null,
            'many() retrieves multiple items (null for missing)'
        );

        // putMany with tags
        $bulkTag = $ctx->prefixed('bulk');
        $taggedKey1 = $ctx->prefixed('bulk:tagged1');
        $taggedKey2 = $ctx->prefixed('bulk:tagged2');

        $ctx->cache->tags([$bulkTag])->putMany([
            $taggedKey1 => 'tagged1',
            $taggedKey2 => 'tagged2',
        ], 60);

        if ($ctx->isAnyMode()) {
            $result->assert(
                $ctx->redis->hexists($ctx->tagHashKey($bulkTag), $taggedKey1) === true
                && $ctx->redis->hexists($ctx->tagHashKey($bulkTag), $taggedKey2) === true,
                'putMany() with tags adds all items to tag hash (any mode)'
            );
        } else {
            // Verify all mode sorted set contains entries
            $tagSetKey = $ctx->tagHashKey($bulkTag);
            $entryCount = $ctx->redis->zCard($tagSetKey);
            $result->assert(
                $entryCount >= 2,
                'putMany() with tags adds entries to tag ZSET (all mode)'
            );
        }

        // Flush putMany tags
        $ctx->cache->tags([$bulkTag])->flush();

        if ($ctx->isAnyMode()) {
            $result->assert(
                $ctx->cache->get($taggedKey1) === null && $ctx->cache->get($taggedKey2) === null,
                'flush() removes items added via putMany()'
            );
        } else {
            $result->assert(
                $ctx->cache->tags([$bulkTag])->get($taggedKey1) === null
                && $ctx->cache->tags([$bulkTag])->get($taggedKey2) === null,
                'flush() removes items added via putMany()'
            );
        }

        return $result;
    }
}
