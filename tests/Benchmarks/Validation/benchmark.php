#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Benchmarks\Validation\Fixtures\Scenarios;
use Hypervel\Validation\Factory;
use Hypervel\Validation\Rule;
use Hypervel\Validation\RulePlanCache;
use Hypervel\Validation\ValidationRuleParser;

use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

Bootstrapper::bootstrap();

class ValidationBenchmark
{
    private int $queries = 0;

    /**
     * Create a new validation benchmark.
     */
    public function __construct(private Factory $factory, Connection $connection)
    {
        $connection->listen(function (): void {
            ++$this->queries;
        });
    }

    /**
     * Verify each selected workload before measuring it.
     */
    public function execute(array $names, int $operations, int $samples, int $warmup, bool $verify): array
    {
        $scenarios = (new Scenarios)->all();
        foreach (['flat', 'fluent', 'database-flat', 'database-conditions'] as $name) {
            $scenarios[$name . '-cold'] = [...$scenarios[$name], 'cold' => true];
        }
        $available = [...array_keys($scenarios), 'retention'];
        $names = $names === ['all'] ? $available : $names;
        $unknown = array_diff($names, $available);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown scenarios: ' . implode(', ', $unknown));
        }

        $results = [];
        foreach ($names as $name) {
            try {
                if ($name === 'retention') {
                    $result = $this->retention($verify);
                } else {
                    $scenario = $scenarios[$name];
                    RulePlanCache::flushState();
                    ValidationRuleParser::flushState();
                    $validator = $this->factory->make($scenario['data'], $scenario['rules']());
                    $passed = $validator->passes();
                    $actual = array_keys($validator->failed());
                    $expected = $scenario['failures'];
                    sort($actual);
                    sort($expected);
                    if ($passed !== ($expected === []) || $actual !== $expected) {
                        throw new LogicException('Incorrect failed attributes: ' . json_encode([
                            'expected' => $expected, 'actual' => $actual,
                        ], JSON_THROW_ON_ERROR));
                    }
                    unset($validator);
                    $result = $verify ? ['status' => 'verified'] : $this->measure(
                        $scenario,
                        max(1, intdiv($operations, $scenario['scale'])),
                        $samples,
                        $warmup === 0 ? 0 : max(1, intdiv($warmup, $scenario['scale'])),
                    );
                }
                $results[$name] = $result;
                printf("%-28s %s\n", $name, isset($result['median_ns'])
                    ? sprintf('%.3f ms, %.2f queries/validation', $result['median_ns'] / 1_000_000, $result['queries_per_validation'])
                    : $result['status']);
            } catch (Throwable $exception) {
                // Preserve other rows when comparing a revision with a known correctness defect.
                $results[$name] = ['status' => 'error', 'error' => $exception::class . ': ' . $exception->getMessage()];
                fwrite(STDERR, $name . ': ' . $results[$name]['error'] . "\n");
            }
        }

        return $results;
    }

    /**
     * Measure complete validation runs, including fresh rule construction where declared.
     */
    private function measure(array $scenario, int $operations, int $samples, int $warmup): array
    {
        $operation = function () use ($scenario): bool {
            return $this->factory->make($scenario['data'], $scenario['rules']())->passes();
        };
        for ($index = 0; $index < $warmup; ++$index) {
            $operation();
        }

        $timings = [];
        $queryCounts = [];
        $peak = 0;
        $endingHeap = [];
        gc_collect_cycles();
        $initialHeap = memory_get_usage();
        for ($sample = 0; $sample < $samples; ++$sample) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $heapBefore = memory_get_usage();
            $queriesBefore = $this->queries;
            $passed = 0;
            $elapsed = 0;
            if ($scenario['cold']) {
                for ($index = 0; $index < $operations; ++$index) {
                    RulePlanCache::flushState();
                    ValidationRuleParser::flushState();
                    $started = hrtime(true);
                    $valid = $operation();
                    $elapsed += hrtime(true) - $started;
                    $passed += (int) $valid;
                }
            } else {
                $started = hrtime(true);
                for ($index = 0; $index < $operations; ++$index) {
                    $passed += (int) $operation();
                }
                $elapsed = hrtime(true) - $started;
            }
            if ($passed !== ($scenario['failures'] === [] ? $operations : 0)) {
                throw new LogicException('Measured validation outcomes changed after verification.');
            }
            $queryCounts[] = ($this->queries - $queriesBefore) / $operations;
            $peak = max($peak, memory_get_peak_usage() - $heapBefore);
            gc_collect_cycles();
            $endingHeap[] = memory_get_usage() - $initialHeap;
            $timings[] = $elapsed / $operations;
        }
        $sorted = $timings;
        sort($sorted, SORT_NUMERIC);
        $middle = intdiv(count($sorted), 2);
        $median = count($sorted) % 2 === 0 ? ($sorted[$middle - 1] + $sorted[$middle]) / 2 : $sorted[$middle];

        return [
            'status' => 'ok',
            'builders' => $scenario['builders'],
            'cache' => $scenario['cold'] ? 'plan-cold' : 'warm',
            'operations_per_sample' => $operations,
            'samples_ns_per_validation' => $timings,
            'median_ns' => $median,
            'p95_sample_average_ns' => $sorted[(int) ceil(count($sorted) * 0.95) - 1],
            'validations_per_second' => 1_000_000_000 / $median,
            'queries_per_validation' => array_sum($queryCounts) / count($queryCounts),
            'sample_queries_per_validation' => $queryCounts,
            'peak_heap_delta_bytes' => $peak,
            'ending_heap_growth_bytes' => $endingHeap,
        ];
    }

    /**
     * Check worker heap growth after the bounded plan cache has filled.
     */
    private function retention(bool $verify): array
    {
        RulePlanCache::flushState();
        ValidationRuleParser::flushState();
        $heaps = [];
        $operations = $verify ? 2 : 4096;
        memory_reset_peak_usage();
        // Each changing block exceeds the default 2048-plan capacity on both compared revisions.
        foreach (['fixed-warmup', 'fixed-repeat', 'changing-fill', 'changing-repeat'] as $block) {
            for ($index = 0; $index < $operations; ++$index) {
                $limit = str_starts_with($block, 'fixed') ? 1 : $index + ($block === 'changing-repeat' ? $operations + 1 : 1);
                $validator = $this->factory->make(['value' => 'x'], ['value' => [Rule::in(['x', (string) $limit])]]);
                if (! $validator->passes()) {
                    throw new LogicException('Retention fixture failed validation.');
                }
                unset($validator);
            }
            gc_collect_cycles();
            $heaps[$block] = memory_get_usage();
        }

        return [
            'status' => $verify ? 'verified' : 'ok',
            'operations_per_block' => $operations,
            'ending_heap_bytes' => $heaps,
            'fixed_growth_bytes' => $heaps['fixed-repeat'] - $heaps['fixed-warmup'],
            'saturated_growth_bytes' => $heaps['changing-repeat'] - $heaps['changing-fill'],
            'peak_heap_bytes' => memory_get_peak_usage(),
        ];
    }
}

/**
 * Create a disposable indexed dataset, outside all measurement windows.
 */
function prepareValidationBenchmarkDatabase(Connection $connection): void
{
    $connection->getSchemaBuilder()->create('validation_benchmark_users', function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->string('code')->unique();
        $table->string('email')->unique();
        $table->double('amount')->index();
        $table->string('status');
        $table->integer('tenant');
        $table->string('literal');
        $table->string('marker');
        $table->dateTime('deleted_at')->nullable();
        $table->jsonb('metadata');
    });
    foreach (array_chunk(range(1, 1200), 100) as $identifiers) {
        $connection->table('validation_benchmark_users')->insert(array_map(static fn (int $identifier): array => [
            'id' => $identifier,
            'code' => 'code-' . $identifier,
            'email' => 'user' . $identifier . '@example.com',
            'amount' => $identifier + 0.25,
            'status' => 'active',
            'tenant' => 1,
            'literal' => 'NULL',
            'marker' => '!value',
            'deleted_at' => null,
            'metadata' => '{"enabled":true,"disabled":false}',
        ], $identifiers));
    }
    $connection->table('validation_benchmark_users')->where('id', 1200)->update(['amount' => '1.234567890123456']);
}

/**
 * Parse a bounded numeric option without silently accepting malformed values.
 */
function integerOption(array $options, string $name, int $default, int $minimum, int $maximum): int
{
    $value = filter_var($options[$name] ?? $default, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException("--{$name} must be an integer from {$minimum} through {$maximum}.");
    }

    return $value;
}

/**
 * Record enough provenance to compare identical workloads on different source revisions.
 */
function benchmarkEnvironment(): array
{
    $git = 'git -C ' . escapeshellarg(dirname(__DIR__, 3));

    return [
        'revision' => trim((string) shell_exec($git . ' rev-parse HEAD')),
        'source_status' => rtrim((string) shell_exec($git . ' status --porcelain -- src')),
        'working_tree_status' => rtrim((string) shell_exec($git . ' status --porcelain')),
        'harness_hashes' => [
            'benchmark.php' => hash_file('xxh128', __FILE__),
            'Fixtures/BenchmarkExists.php' => hash_file('xxh128', __DIR__ . '/Fixtures/BenchmarkExists.php'),
            'Fixtures/Scenarios.php' => hash_file('xxh128', __DIR__ . '/Fixtures/Scenarios.php'),
        ],
        'lock_hash' => hash_file('xxh128', dirname(__DIR__, 3) . '/composer.lock'),
        'php' => PHP_VERSION,
        'extensions' => get_loaded_extensions(),
        'opcache_cli' => ini_get('opcache.enable_cli'),
        'jit' => ini_get('opcache.jit'),
        'jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
        'precision' => ini_get('precision'),
        'serialize_precision' => ini_get('serialize_precision'),
        'gc_enabled' => gc_enabled(),
        'os' => php_uname(),
    ];
}

/**
 * Run validation in the same disposable application used by the other developer harnesses.
 */
function main(): int
{
    $options = getopt('', ['scenarios:', 'operations:', 'samples:', 'warmup:', 'json:', 'verify', 'help']);
    if ($options === false) {
        throw new InvalidArgumentException('Unable to parse options.');
    }
    if (isset($options['help'])) {
        echo "Usage: php tests/Benchmarks/Validation/benchmark.php [--scenarios=all] [--operations=20000] [--samples=11] [--warmup=1000] [--json=PATH] [--verify]\n";

        return 0;
    }
    try {
        $operations = integerOption($options, 'operations', 20000, 1, 1000000);
        $samples = integerOption($options, 'samples', 11, 1, 100);
        $warmup = integerOption($options, 'warmup', 1000, 0, 100000);
        $names = array_values(array_unique(array_map(trim(...), explode(',', $options['scenarios'] ?? 'all'))));
        $verify = array_key_exists('verify', $options);
        $report = ['environment' => benchmarkEnvironment(), 'options' => compact('operations', 'samples', 'warmup', 'verify')];
        $databasePath = tempnam(sys_get_temp_dir(), 'hypervel-validation-benchmark-');
        if ($databasePath === false) {
            throw new RuntimeException('Unable to create a temporary database.');
        }
        $application = null;
        try {
            $application = Application::create(options: ['load_environment_variables' => false]);
            $config = $application->make(Repository::class);
            $config->set('database.default', 'sqlite');
            $config->set('database.connections.sqlite.database', $databasePath);
            $config->set('database.connections.sqlite.url', null);
            $connection = $application->make(DatabaseManager::class)->connection();
            $exception = null;
            $completed = run(function () use ($application, $connection, $names, $operations, $samples, $warmup, $verify, &$report, &$exception): void {
                try {
                    prepareValidationBenchmarkDatabase($connection);
                    $report['results'] = (new ValidationBenchmark($application->make(Factory::class), $connection))
                        ->execute($names, $operations, $samples, $warmup, $verify);
                } catch (Throwable $caught) {
                    $exception = $caught;
                }
            });
            if ($exception !== null) {
                throw $exception;
            }
            if (! $completed || ! isset($report['results'])) {
                throw new RuntimeException('The benchmark coroutine did not complete.');
            }
        } finally {
            $application?->terminate();
            unlink($databasePath);
        }
        if (isset($options['json'])) {
            if (file_put_contents($options['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
                throw new RuntimeException('Unable to write report.');
            }
        }

        return array_any($report['results'], static fn (array $result): bool => $result['status'] === 'error') ? 1 : 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");

        return 1;
    }
}

exit(main());
