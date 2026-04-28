<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests read performance after tagged write operations.
 */
class ReadPerformanceScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Read Performance';
    }

    /**
     * Run read performance benchmark after tagged writes.
     */
    public function run(BenchmarkContext $context): ScenarioResult
    {
        $items = $context->items;
        $context->newLine();
        $context->line('  Running Read Performance Scenario...');
        $context->cleanup();

        $store = $context->getStore();
        $chunkSize = 100;

        // Seed data
        $bar = $context->createProgressBar($items);

        $tag = $context->prefixed('read:tag');

        for ($i = 0; $i < $items; ++$i) {
            $store->tags([$tag])->put($context->prefixed("read:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

        // Read performance
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);

        // In 'any' mode, items can be read directly without specifying tags
        // In 'all' mode, items must be read with the same tags used when storing
        $isAnyMode = $context->getStoreInstance()->getTagMode()->isAnyMode();

        for ($i = 0; $i < $items; ++$i) {
            if ($isAnyMode) {
                $store->get($context->prefixed("read:{$i}"));
            } else {
                $store->tags([$tag])->get($context->prefixed("read:{$i}"));
            }

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

        $readTime = (hrtime(true) - $start) / 1e9;
        $readRate = $items / $readTime;

        return new ScenarioResult([
            'read_rate' => $readRate,
        ]);
    }
}
