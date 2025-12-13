<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests heavy tagging with many tags per item.
 */
class HeavyTaggingScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Heavy Tagging';
    }

    /**
     * Run heavy tagging benchmark with many tags per item.
     */
    public function run(BenchmarkContext $ctx): ScenarioResult
    {
        $tagsPerItem = $ctx->heavyTags;

        // Reduce items for heavy tagging to keep benchmark time reasonable
        $adjustedItems = max(100, (int) ($ctx->items / 5));

        $ctx->newLine();
        $ctx->line("  Running Heavy Tagging Scenario ({$adjustedItems} items, {$tagsPerItem} tags/item)...");
        $ctx->cleanup();

        // Build tags array
        $tags = [];

        for ($i = 0; $i < $tagsPerItem; ++$i) {
            $tags[] = $ctx->prefixed("heavy:tag:{$i}");
        }

        // 1. Write
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($adjustedItems);
        $store = $ctx->getStore();

        $chunkSize = 10;

        for ($i = 0; $i < $adjustedItems; ++$i) {
            $store->tags($tags)->put($ctx->prefixed("heavy:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $writeTime = (hrtime(true) - $start) / 1e9;
        $writeRate = $adjustedItems / $writeTime;

        // 2. Flush (Flush one tag)
        $ctx->line('  Flushing heavy items by single tag...');
        $start = hrtime(true);
        $store->tags([$tags[0]])->flush();
        $flushTime = (hrtime(true) - $start) / 1e9;

        return new ScenarioResult([
            'write_rate' => $writeRate,
            'flush_time' => $flushTime,
        ]);
    }
}
