<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Tests non-tagged cache operations (put, get, forget, remember, putMany, add).
 */
class NonTaggedScenario implements ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string
    {
        return 'Non-Tagged Operations';
    }

    /**
     * Run non-tagged cache operations benchmark.
     */
    public function run(BenchmarkContext $ctx): ScenarioResult
    {
        $items = $ctx->items;
        $ctx->newLine();
        $ctx->line("  Running Non-Tagged Operations Scenario ({$items} items)...");
        $ctx->cleanup();

        $store = $ctx->getStore();
        $chunkSize = 100;

        // 1. Write Performance (put)
        $ctx->line('  Testing put()...');
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        for ($i = 0; $i < $items; $i++) {
            $store->put($ctx->prefixed("nontagged:put:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $putTime = (hrtime(true) - $start) / 1e9;
        $putRate = $items / $putTime;

        // 2. Read Performance (get)
        $ctx->line('  Testing get()...');
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        for ($i = 0; $i < $items; $i++) {
            $store->get($ctx->prefixed("nontagged:put:{$i}"));

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $getTime = (hrtime(true) - $start) / 1e9;
        $getRate = $items / $getTime;

        // 3. Delete Performance (forget)
        $ctx->line('  Testing forget()...');
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        for ($i = 0; $i < $items; $i++) {
            $store->forget($ctx->prefixed("nontagged:put:{$i}"));

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $forgetTime = (hrtime(true) - $start) / 1e9;
        $forgetRate = $items / $forgetTime;

        // 4. Remember Performance (cache miss + store)
        $ctx->line('  Testing remember()...');
        $rememberItems = min(1000, (int) ($items / 10));
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($rememberItems);
        $rememberChunk = 10;

        for ($i = 0; $i < $rememberItems; $i++) {
            $store->remember($ctx->prefixed("nontagged:remember:{$i}"), 3600, function (): string {
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
        $ctx->line('  Testing putMany()...');
        $ctx->cleanup();
        $bulkChunkSize = 100;
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        $buffer = [];

        for ($i = 0; $i < $items; $i++) {
            $buffer[$ctx->prefixed("nontagged:bulk:{$i}")] = 'value';

            if (count($buffer) >= $bulkChunkSize) {
                $store->putMany($buffer, 3600);
                $buffer = [];
                $bar->advance($bulkChunkSize);
            }
        }

        if (! empty($buffer)) {
            $store->putMany($buffer, 3600);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $ctx->line('');

        $putManyTime = (hrtime(true) - $start) / 1e9;
        $putManyRate = $items / $putManyTime;

        // 6. Add Performance (add)
        $ctx->line('  Testing add()...');
        $ctx->cleanup();
        $start = hrtime(true);
        $bar = $ctx->createProgressBar($items);

        for ($i = 0; $i < $items; $i++) {
            $store->add($ctx->prefixed("nontagged:add:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $ctx->checkMemoryUsage();
            }
        }

        $bar->finish();
        $ctx->line('');

        $addTime = (hrtime(true) - $start) / 1e9;
        $addRate = $items / $addTime;

        return new ScenarioResult([
            'put_rate' => $putRate,
            'get_rate' => $getRate,
            'forget_rate' => $forgetRate,
            'remember_rate' => $rememberRate,
            'putmany_rate' => $putManyRate,
            'add_rate' => $addRate,
        ]);
    }
}
