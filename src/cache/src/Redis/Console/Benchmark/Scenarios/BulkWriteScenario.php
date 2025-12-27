<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests bulk write (putMany) performance with tags.
 */
class BulkWriteScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Bulk Write';
    }

    /**
     * Run bulk write (putMany) benchmark with tags.
     */
    public function run(BenchmarkContext $ctx): ScenarioResult
    {
        $items = $ctx->items;
        $ctx->newLine();
        $ctx->line("  Running Bulk Write Scenario (putMany, {$items} items)...");
        $ctx->cleanup();

        $store = $ctx->getStore();
        $chunkSize = 100;

        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        $tag = $ctx->prefixed('bulk:tag');
        $buffer = [];

        for ($i = 0; $i < $items; ++$i) {
            $buffer[$ctx->prefixed("bulk:{$i}")] = 'value';

            if (count($buffer) >= $chunkSize) {
                $store->tags([$tag])->putMany($buffer, 3600);
                $buffer = [];
                $bar->advance($chunkSize);
            }
        }

        if (! empty($buffer)) {
            $store->tags([$tag])->putMany($buffer, 3600);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $ctx->line('');

        $writeTime = (hrtime(true) - $start) / 1e9;
        $writeRate = $items / $writeTime;

        return new ScenarioResult([
            'write_rate' => $writeRate,
        ]);
    }
}
