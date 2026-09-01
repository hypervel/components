<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

final readonly class InstrumentationOptions
{
    /**
     * Create an immutable instrumentation option view.
     *
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * Get an option value.
     */
    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Determine whether a boolean option is enabled.
     */
    public function enabled(string $key): bool
    {
        return $this->values[$key] ?? false;
    }

    /**
     * Determine whether a metric is enabled.
     */
    public function metricEnabled(string $name): bool
    {
        $metrics = $this->values['metrics'] ?? false;

        return is_bool($metrics) ? $metrics : ($metrics[$name] ?? false);
    }
}
