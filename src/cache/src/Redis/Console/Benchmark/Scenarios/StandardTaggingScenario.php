<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests standard tagging with write and flush operations.
 */
class StandardTaggingScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Standard Tagging';
    }

    /**
     * Run standard tagging benchmark with write and flush operations.
     */
    public function run(BenchmarkContext $ctx): ScenarioResult
    {
        $items = $ctx->items;
        $tagsPerItem = $ctx->tagsPerItem;

        $ctx->newLine();
        $ctx->line("  Running Standard Tagging Scenario ({$items} items, {$tagsPerItem} tags/item)...");
        $ctx->cleanup();

        // Build tags array
        $tags = [];

        for ($i = 0; $i < $tagsPerItem; ++$i) {
            $tags[] = $ctx->prefixed("tag:{$i}");
        }

        // 1. Write
        $ctx->line('  Testing put() with tags...');
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        $store = $ctx->getStore();
        $chunkSize = 100;

        for ($i = 0; $i < $items; ++$i) {
            $store->tags($tags)->put($ctx->prefixed("item:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $writeTime = (hrtime(true) - $start) / 1e9;
        $writeRate = $items / $writeTime;

        // 2. Flush (Flush one tag, which removes all $items items since all share this tag)
        $ctx->line("  Flushing {$items} items via 1 tag...");
        $start = hrtime(true);
        $store->tags([$tags[0]])->flush();
        $flushTime = (hrtime(true) - $start) / 1e9;

        // 3. Add Performance (add)
        $ctx->cleanup();
        $ctx->line('  Testing add() with tags...');
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        for ($i = 0; $i < $items; ++$i) {
            $store->tags($tags)->add($ctx->prefixed("item:add:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $addTime = (hrtime(true) - $start) / 1e9;
        $addRate = $items / $addTime;

        // 4. Remember Performance (cache miss + store with tags)
        $ctx->cleanup();
        $ctx->line('  Testing remember() with tags...');
        $rememberItems = min(1000, (int) ($items / 10));
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($rememberItems);
        $rememberChunk = 10;

        for ($i = 0; $i < $rememberItems; ++$i) {
            $store->tags($tags)->remember($ctx->prefixed("item:remember:{$i}"), 3600, function (): string {
                return 'computed_value';
            });

            if ($i % $rememberChunk === 0) {
                $bar->advance($rememberChunk);
            }
        }

        $bar->finish();
        $ctx->line('');

        $rememberTime = (hrtime(true) - $start) / 1e9;
        $rememberRate = $rememberItems / $rememberTime;

        // 5. Bulk Write Performance (putMany)
        $ctx->cleanup();
        $ctx->line('  Testing putMany() with tags...');
        $bulkChunkSize = 100;
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        $buffer = [];

        for ($i = 0; $i < $items; ++$i) {
            $buffer[$ctx->prefixed("item:bulk:{$i}")] = 'value';

            if (count($buffer) >= $bulkChunkSize) {
                $store->tags($tags)->putMany($buffer, 3600);
                $buffer = [];
                $bar->advance($bulkChunkSize);
            }
        }

        if (! empty($buffer)) {
            $store->tags($tags)->putMany($buffer, 3600);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $ctx->line('');

        $putManyTime = (hrtime(true) - $start) / 1e9;
        $putManyRate = $items / $putManyTime;

        return new ScenarioResult([
            'write_time' => $writeTime,
            'write_rate' => $writeRate,
            'flush_time' => $flushTime,
            'add_rate' => $addRate,
            'remember_rate' => $rememberRate,
            'putmany_rate' => $putManyRate,
        ]);
    }
}
