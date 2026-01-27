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

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();
        $count = self::ITEM_COUNT;
        $tag = $context->prefixed('large-set');

        // Bulk insert
        $startTime = microtime(true);

        for ($i = 0; $i < $count; ++$i) {
            $context->cache->tags([$tag])->put($context->prefixed("large:item{$i}"), "value{$i}", 60);
        }

        $insertTime = microtime(true) - $startTime;

        $firstKey = $context->prefixed('large:item0');
        $lastKey = $context->prefixed('large:item' . ($count - 1));

        if ($context->isAnyMode()) {
            $firstValue = $context->cache->get($firstKey);
            $lastValue = $context->cache->get($lastKey);
        } else {
            $firstValue = $context->cache->tags([$tag])->get($firstKey);
            $lastValue = $context->cache->tags([$tag])->get($lastKey);
        }

        $result->assert(
            $firstValue === 'value0' && $lastValue === 'value' . ($count - 1),
            "Inserted {$count} items (took " . number_format($insertTime, 2) . 's)'
        );

        // Bulk flush
        $startTime = microtime(true);
        $context->cache->tags([$tag])->flush();
        $flushTime = microtime(true) - $startTime;

        if ($context->isAnyMode()) {
            $firstAfterFlush = $context->cache->get($firstKey);
            $lastAfterFlush = $context->cache->get($lastKey);
        } else {
            $firstAfterFlush = $context->cache->tags([$tag])->get($firstKey);
            $lastAfterFlush = $context->cache->tags([$tag])->get($lastKey);
        }

        $result->assert(
            $firstAfterFlush === null && $lastAfterFlush === null,
            "Flushed {$count} items (took " . number_format($flushTime, 2) . 's)'
        );

        return $result;
    }
}
