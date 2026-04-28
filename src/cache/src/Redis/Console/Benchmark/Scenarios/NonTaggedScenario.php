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
    public function run(BenchmarkContext $context): ScenarioResult
    {
        $items = $context->items;
        $context->newLine();
        $context->line("  Running Non-Tagged Operations Scenario ({$items} items)...");
        $context->cleanup();

        $store = $context->getStore();
        $chunkSize = 100;

        // 1. Write Performance (put)
        $context->line('  Testing put()...');
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);

        for ($i = 0; $i < $items; ++$i) {
            $store->put($context->prefixed("nontagged:put:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

        $putTime = (hrtime(true) - $start) / 1e9;
        $putRate = $items / $putTime;

        // 2. Read Performance (get)
        $context->line('  Testing get()...');
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);

        for ($i = 0; $i < $items; ++$i) {
            $store->get($context->prefixed("nontagged:put:{$i}"));

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

        $getTime = (hrtime(true) - $start) / 1e9;
        $getRate = $items / $getTime;

        // 3. Delete Performance (forget)
        $context->line('  Testing forget()...');
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);

        for ($i = 0; $i < $items; ++$i) {
            $store->forget($context->prefixed("nontagged:put:{$i}"));

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

        $forgetTime = (hrtime(true) - $start) / 1e9;
        $forgetRate = $items / $forgetTime;

        // 4. Remember Performance (cache miss + store)
        $context->line('  Testing remember()...');
        $rememberItems = min(1000, (int) ($items / 10));
        $start = hrtime(true);
        $bar = $context->createProgressBar($rememberItems);
        $rememberChunk = 10;

        for ($i = 0; $i < $rememberItems; ++$i) {
            $store->remember($context->prefixed("nontagged:remember:{$i}"), 3600, function (): string {
                return 'computed_value';
            });

            if ($i % $rememberChunk === 0) {
                $bar->advance($rememberChunk);
            }
        }

        $bar->finish();
        $context->line('');

        $rememberTime = (hrtime(true) - $start) / 1e9;
        $rememberRate = $rememberItems / $rememberTime;

        // 5. Bulk Write Performance (putMany)
        $context->line('  Testing putMany()...');
        $context->cleanup();
        $bulkChunkSize = 100;
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);

        $buffer = [];

        for ($i = 0; $i < $items; ++$i) {
            $buffer[$context->prefixed("nontagged:bulk:{$i}")] = 'value';

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
        $context->line('');

        $putManyTime = (hrtime(true) - $start) / 1e9;
        $putManyRate = $items / $putManyTime;

        // 6. Add Performance (add)
        $context->line('  Testing add()...');
        $context->cleanup();
        $start = hrtime(true);
        $bar = $context->createProgressBar($items);

        for ($i = 0; $i < $items; ++$i) {
            $store->add($context->prefixed("nontagged:add:{$i}"), 'value', 3600);

            if ($i % $chunkSize === 0) {
                $bar->advance($chunkSize);
                $context->checkMemoryUsage();
            }
        }

        $bar->finish();
        $context->line('');

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
