<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Tests performance with large datasets (500+ items).
 *
 * This check is mode-agnostic.
 */
final class LargeDatasetCheck implements CheckInterface
{
    private const ITEM_COUNT = 500;

    public function name(): string
    {
        return 'Large Dataset Operations';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();
        $count = self::ITEM_COUNT;
        $tag = $ctx->prefixed('large-set');

        // Bulk insert
        $startTime = microtime(true);

        for ($i = 0; $i < $count; ++$i) {
            $ctx->cache->tags([$tag])->put($ctx->prefixed("large:item{$i}"), "value{$i}", 60);
        }

        $insertTime = microtime(true) - $startTime;

        $firstKey = $ctx->prefixed('large:item0');
        $lastKey = $ctx->prefixed('large:item' . ($count - 1));

        if ($ctx->isAnyMode()) {
            $firstValue = $ctx->cache->get($firstKey);
            $lastValue = $ctx->cache->get($lastKey);
        } else {
            $firstValue = $ctx->cache->tags([$tag])->get($firstKey);
            $lastValue = $ctx->cache->tags([$tag])->get($lastKey);
        }

        $result->assert(
            $firstValue === 'value0' && $lastValue === 'value' . ($count - 1),
            "Inserted {$count} items (took " . number_format($insertTime, 2) . 's)'
        );

        // Bulk flush
        $startTime = microtime(true);
        $ctx->cache->tags([$tag])->flush();
        $flushTime = microtime(true) - $startTime;

        if ($ctx->isAnyMode()) {
            $firstAfterFlush = $ctx->cache->get($firstKey);
            $lastAfterFlush = $ctx->cache->get($lastKey);
        } else {
            $firstAfterFlush = $ctx->cache->tags([$tag])->get($firstKey);
            $lastAfterFlush = $ctx->cache->tags([$tag])->get($lastKey);
        }

        $result->assert(
            $firstAfterFlush === null && $lastAfterFlush === null,
            "Flushed {$count} items (took " . number_format($flushTime, 2) . 's)'
        );

        return $result;
    }
}
