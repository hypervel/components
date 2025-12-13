<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Benchmark;

use Hyperf\Command\Command;
use Hypervel\Support\Number;

/**
 * Formats and displays benchmark results.
 */
class ResultsFormatter
{
    /**
     * The output interface.
     */
    private Command $command;

    /**
     * Metric display configuration grouped by category.
     * Order here determines display order.
     *
     * @var array<string, array<string, array{label: string, unit: string, format: string, better: string, scenario: string}>>
     */
    private array $metricGroups = [
        'Non-Tagged Operations' => [
            'put_rate' => ['label' => 'put()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'nontagged'],
            'get_rate' => ['label' => 'get()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'nontagged'],
            'forget_rate' => ['label' => 'forget()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'nontagged'],
            'add_rate_nontagged' => ['label' => 'add()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'nontagged', 'key' => 'add_rate'],
            'remember_rate' => ['label' => 'remember()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'nontagged'],
            'putmany_rate_nontagged' => ['label' => 'putMany()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'nontagged', 'key' => 'putmany_rate'],
        ],
        'Tagged Operations' => [
            'write_rate' => ['label' => 'put()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'standard'],
            'read_rate' => ['label' => 'get()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'read'],
            'add_rate_tagged' => ['label' => 'add()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'standard', 'key' => 'add_rate'],
            'remember_rate_tagged' => ['label' => 'remember()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'standard', 'key' => 'remember_rate'],
            'putmany_rate_tagged' => ['label' => 'putMany()', 'unit' => 'Items/sec', 'format' => 'rate', 'better' => 'higher', 'scenario' => 'standard', 'key' => 'putmany_rate'],
            'flush_time' => ['label' => 'flush() 1 tag', 'unit' => 'Seconds', 'format' => 'time', 'better' => 'lower', 'scenario' => 'standard'],
        ],
        'Maintenance' => [
            'cleanup_time' => ['label' => 'Prune stale tags', 'unit' => 'Seconds', 'format' => 'time', 'better' => 'lower', 'scenario' => 'cleanup'],
        ],
    ];

    /**
     * Create a new results formatter instance.
     */
    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    /**
     * Display results table for a single mode.
     *
     * @param array<string, ScenarioResult> $results
     */
    public function displayResultsTable(array $results, string $tagMode): void
    {
        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════════════');
        $this->command->info("  Results ({$tagMode} mode)");
        $this->command->info('═══════════════════════════════════════════════════════════════');
        $this->command->newLine();

        $tableData = [];

        foreach ($this->metricGroups as $groupName => $metrics) {
            $groupHasData = false;

            foreach ($metrics as $metricId => $config) {
                $scenario = $config['scenario'];
                $metricKey = $config['key'] ?? $metricId;

                if (! isset($results[$scenario])) {
                    continue;
                }

                $value = $results[$scenario]->get($metricKey);

                if ($value === null) {
                    continue;
                }

                if (! $groupHasData) {
                    // Add group header as a separator row
                    $tableData[] = ["<fg=yellow>{$groupName}</>", ''];
                    $groupHasData = true;
                }

                $tableData[] = [
                    '  ' . $config['label'] . ' (' . $config['unit'] . ')',
                    $this->formatValue($value, $config['format']),
                ];
            }
        }

        $this->command->table(
            ['Metric', 'Result'],
            $tableData
        );
    }

    /**
     * Display comparison table between two tag modes.
     *
     * @param array<string, ScenarioResult> $allModeResults
     * @param array<string, ScenarioResult> $anyModeResults
     */
    public function displayComparisonTable(array $allModeResults, array $anyModeResults): void
    {
        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════════════');
        $this->command->info('  Tag Mode Comparison: All vs Any');
        $this->command->info('═══════════════════════════════════════════════════════════════');
        $this->command->newLine();

        $tableData = [];

        foreach ($this->metricGroups as $groupName => $metrics) {
            $groupHasData = false;

            foreach ($metrics as $metricId => $config) {
                $scenario = $config['scenario'];
                $metricKey = $config['key'] ?? $metricId;

                $allValue = isset($allModeResults[$scenario]) ? $allModeResults[$scenario]->get($metricKey) : null;
                $anyValue = isset($anyModeResults[$scenario]) ? $anyModeResults[$scenario]->get($metricKey) : null;

                if ($allValue === null && $anyValue === null) {
                    continue;
                }

                if (! $groupHasData) {
                    // Add group header as a separator row
                    $tableData[] = ["<fg=yellow>{$groupName}</>", '', '', ''];
                    $groupHasData = true;
                }

                $diff = $this->calculateDiff($allValue, $anyValue, $config['better']);

                $tableData[] = [
                    '  ' . $config['label'] . ' (' . $config['unit'] . ')',
                    $allValue !== null ? $this->formatValue($allValue, $config['format']) : 'N/A',
                    $anyValue !== null ? $this->formatValue($anyValue, $config['format']) : 'N/A',
                    $diff,
                ];
            }
        }

        $this->command->table(
            ['Metric', 'All Mode', 'Any Mode', 'Diff'],
            $tableData
        );

        $this->displayLegend();
    }

    /**
     * Display the legend explaining color coding.
     */
    private function displayLegend(): void
    {
        $this->command->newLine();
        $this->command->line('  <fg=gray>Legend: Diff shows Any Mode relative to All Mode</>');
        $this->command->line('  <fg=green>Green (+%)</> = Any Mode is better');
        $this->command->line('  <fg=red>Red (-%)</> = Any Mode is worse');
        $this->command->line('  <fg=gray>For times, lower is better. For rates, higher is better.</>');
    }

    /**
     * Format a value based on its type.
     */
    private function formatValue(float $value, string $format): string
    {
        return match ($format) {
            'rate' => Number::format($value, precision: 0),
            'time' => Number::format($value, precision: 4) . 's',
            default => (string) $value,
        };
    }

    /**
     * Calculate the percentage difference and format with color.
     */
    private function calculateDiff(?float $allValue, ?float $anyValue, string $better): string
    {
        if ($allValue === null || $anyValue === null || $allValue == 0) {
            return '<fg=gray>-</>';
        }

        // Calculate percentage difference: (any - all) / all * 100
        $percentDiff = (($anyValue - $allValue) / $allValue) * 100;

        // Determine if "any" mode is better
        // For rates (higher is better): positive diff = any is better
        // For times (lower is better): negative diff = any is better
        $anyIsBetter = ($better === 'higher' && $percentDiff > 0)
                    || ($better === 'lower' && $percentDiff < 0);

        $color = $anyIsBetter ? 'green' : 'red';
        $sign = $percentDiff >= 0 ? '+' : '';

        return sprintf('<fg=%s>%s%.1f%%</>', $color, $sign, $percentDiff);
    }
}
