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
    public function run(BenchmarkContext $context): ScenarioResult
    {
        // Reduce items slightly for cleanup test
        $adjustedItems = max(100, (int) ($context->items / 2));

        $context->newLine();
        $context->line("  Running Cleanup Scenario ({$adjustedItems} items, shared tags)...");
        $context->cleanup();

        $mainTag = $context->prefixed('cleanup:main');
        $sharedTags = [
            $context->prefixed('cleanup:shared:1'),
            $context->prefixed('cleanup:shared:2'),
            $context->prefixed('cleanup:shared:3'),
        ];
        $allTags = array_merge([$mainTag], $sharedTags);

        // 1. Write items with shared tags
        $bar = $context->createProgressBar($adjustedItems);
        $store = $context->getStore();

        for ($i = 0; $i < $adjustedItems; ++$i) {
            $store->tags($allTags)->put($context->prefixed("cleanup:{$i}"), 'value', 3600);

            if ($i % 100 === 0) {
                $bar->advance(100);
            }
        }

        $bar->finish();
        $context->line('');

        // 2. Flush main tag (creates orphans in shared tags in any mode)
        $context->line('  Flushing main tag...');
        $store->tags([$mainTag])->flush();

        // 3. Run Cleanup
        $context->line('  Running cleanup command...');
        $context->newLine();
        $start = hrtime(true);

        $context->call('cache:prune-redis-stale-tags', ['store' => $context->storeName]);

        $cleanupTime = (hrtime(true) - $start) / 1e9;

        return new ScenarioResult([
            'cleanup_time' => $cleanupTime,
        ]);
    }
}
