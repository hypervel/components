<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests deep tagging with a single tag across many items.
 */
class DeepTaggingScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Deep Tagging';
    }

    /**
     * Run deep tagging benchmark with a single tag across many items.
     */
    public function run(BenchmarkContext $context): ScenarioResult
    {
        $items = $context->items;
        $context->newLine();
        $context->line("  Running Deep Tagging Scenario (1 tag, {$items} items)...");
        $context->cleanup();

        $tag = $context->prefixed('deep:tag');

        // 1. Write
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);
        $store = $context->getStore();

        $chunkSize = 100;

        for ($i = 0; $i < $items; ++$i) {
            $store->tags([$tag])->put($context->prefixed("deep:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

        // 2. Flush
        $context->line('  Flushing deep tag...');
        $start = hrtime(true);
        $store->tags([$tag])->flush();
        $flushTime = (hrtime(true) - $start) / 1e9;

        return new ScenarioResult([
            'flush_time' => $flushTime,
        ]);
    }
}
