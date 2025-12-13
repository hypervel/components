<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests cleanup command performance after creating orphaned tags.
 */
class CleanupScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Cleanup Performance';
    }

    /**
     * Run cleanup command performance benchmark.
     */
    public function run(BenchmarkContext $ctx): ScenarioResult
    {
        // Reduce items slightly for cleanup test
        $adjustedItems = max(100, (int) ($ctx->items / 2));

        $ctx->newLine();
        $ctx->line("  Running Cleanup Scenario ({$adjustedItems} items, shared tags)...");
        $ctx->cleanup();

        $mainTag = $ctx->prefixed('cleanup:main');
        $sharedTags = [
            $ctx->prefixed('cleanup:shared:1'),
            $ctx->prefixed('cleanup:shared:2'),
            $ctx->prefixed('cleanup:shared:3'),
        ];
        $allTags = array_merge([$mainTag], $sharedTags);

        // 1. Write items with shared tags
        $bar = $ctx->createProgressBar($adjustedItems);
        $store = $ctx->getStore();

        for ($i = 0; $i < $adjustedItems; ++$i) {
            $store->tags($allTags)->put($ctx->prefixed("cleanup:{$i}"), 'value', 3600);

            if ($i % 100 === 0) {
                $bar->advance(100);
            }
        }

        $bar->finish();
        $ctx->line('');

        // 2. Flush main tag (creates orphans in shared tags in any mode)
        $ctx->line('  Flushing main tag...');
        $store->tags([$mainTag])->flush();

        // 3. Run Cleanup
        $ctx->line('  Running cleanup command...');
        $ctx->newLine();
        $start = hrtime(true);

        $ctx->call('cache:prune-redis-stale-tags', ['store' => $ctx->storeName]);

        $cleanupTime = (hrtime(true) - $start) / 1e9;

        return new ScenarioResult([
            'cleanup_time' => $cleanupTime,
        ]);
    }
}
