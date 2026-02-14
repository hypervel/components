<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark\Scenarios;

use Hypervel\Cache\Redis\Console\Benchmark\BenchmarkContext;
use Hypervel\Cache\Redis\Console\Benchmark\ScenarioResult;

/**
 * Interface for benchmark scenarios.
 */
interface ScenarioInterface
{
    /**
     * Get the scenario name for display.
     */
    public function name(): string;

    /**
     * Run the scenario and return results.
     */
    public function run(BenchmarkContext $context): ScenarioResult;
}
