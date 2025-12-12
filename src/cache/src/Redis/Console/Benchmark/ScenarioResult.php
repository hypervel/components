<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark;

/**
 * Simple container for benchmark scenario results.
 */
class ScenarioResult
{
    /**
     * Create a new scenario result instance.
     *
     * @param  array<string, float>  $metrics  Metric name => value (e.g., ['write_rate' => 1234.5, 'flush_time' => 0.05])
     */
    public function __construct(
        public readonly array $metrics,
    ) {}

    /**
     * Get a specific metric value.
     */
    public function get(string $key): ?float
    {
        return $this->metrics[$key] ?? null;
    }

    /**
     * Convert to array for compatibility.
     */
    public function toArray(): array
    {
        return $this->metrics;
    }
}
