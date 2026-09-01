#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Support\DataObject;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

class LegacyDataBenchmarkAddress extends DataObject
{
    public function __construct(
        public string $lineOne,
        public string $city,
        public string $countryCode,
    ) {
    }
}

class LegacyDataBenchmarkUser extends DataObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $active,
        public ?LegacyDataBenchmarkAddress $address,
    ) {
    }
}

class DataBenchmark
{
    /**
     * Create a new data benchmark.
     */
    public function __construct(
        private readonly int $operations,
        private readonly int $samples,
        private readonly int $warmup,
    ) {
    }

    /**
     * Run every benchmark scenario.
     *
     * @return array{environment: array<string, mixed>, results: list<array<string, float|int|string>>}
     */
    public function execute(): array
    {
        $environment = $this->environment();
        $results = [];

        $flatPayload = [
            'id' => 1001,
            'name' => 'Taylor Otwell',
            'email' => 'taylor@example.com',
            'active' => true,
            'address' => null,
        ];
        $nestedPayload = [
            ...$flatPayload,
            'address' => [
                'line_one' => '1 Framework Way',
                'city' => 'Little Rock',
                'country_code' => 'US',
            ],
        ];

        $scenarios = [
            'native-constructor' => fn (): int => (new LegacyDataBenchmarkUser(
                $flatPayload['id'],
                $flatPayload['name'],
                $flatPayload['email'],
                $flatPayload['active'],
                null,
            ))->id,
            'manual-flat-mapper' => fn (): int => $this->mapUser($flatPayload)->id,
            'data-object-flat-warm' => fn (): int => LegacyDataBenchmarkUser::make($flatPayload)->id,
            'data-object-flat-cold' => function () use ($flatPayload): int {
                DataObject::flushState();

                return LegacyDataBenchmarkUser::make($flatPayload)->id;
            },
            'manual-nested-mapper' => fn (): int => $this->mapUser($nestedPayload)->address?->countryCode === 'US' ? 1 : 0,
            'data-object-nested-warm' => fn (): int => LegacyDataBenchmarkUser::make($nestedPayload, true)->address?->countryCode === 'US' ? 1 : 0,
        ];

        printf("Hypervel data benchmark\n");

        foreach ($environment as $key => $value) {
            printf("%s: %s\n", $key, is_array($value) ? implode(', ', $value) : (string) $value);
        }

        printf(
            "\n%-28s %14s %14s %14s %14s\n",
            'scenario',
            'operations/s',
            'p50 ns/op',
            'p95 ns/op',
            'peak memory',
        );

        foreach ($scenarios as $name => $scenario) {
            $result = $this->benchmark($name, $scenario);
            $results[] = $result;

            printf(
                "%-28s %14.0f %14.2f %14.2f %14d\n",
                $result['scenario'],
                $result['operations_per_second'],
                $result['p50_nanoseconds'],
                $result['p95_nanoseconds'],
                $result['peak_memory_bytes'],
            );
        }

        return compact('environment', 'results');
    }

    /**
     * Benchmark one operation over repeated samples.
     *
     * @param Closure(): int $operation
     * @return array<string, float|int|string>
     */
    private function benchmark(string $name, Closure $operation): array
    {
        $checksum = 0;

        for ($iteration = 0; $iteration < $this->warmup; ++$iteration) {
            $checksum += $operation();
        }

        $nanosecondsPerOperation = [];
        $peakMemory = 0;

        for ($sample = 0; $sample < $this->samples; ++$sample) {
            memory_reset_peak_usage();
            $startedAt = hrtime(true);

            for ($operationIndex = 0; $operationIndex < $this->operations; ++$operationIndex) {
                $checksum += $operation();
            }

            $elapsedNanoseconds = hrtime(true) - $startedAt;
            $nanosecondsPerOperation[] = $elapsedNanoseconds / $this->operations;
            $peakMemory = max($peakMemory, memory_get_peak_usage(true));
        }

        if ($checksum === 0) {
            throw new LogicException("Benchmark scenario [{$name}] produced an empty checksum.");
        }

        sort($nanosecondsPerOperation, SORT_NUMERIC);
        $median = $this->percentile($nanosecondsPerOperation, 0.50);

        return [
            'scenario' => $name,
            'operations' => $this->operations,
            'samples' => $this->samples,
            'operations_per_second' => 1_000_000_000 / $median,
            'p50_nanoseconds' => $median,
            'p95_nanoseconds' => $this->percentile($nanosecondsPerOperation, 0.95),
            'peak_memory_bytes' => $peakMemory,
        ];
    }

    /**
     * Map one representative SDK payload without reflection.
     */
    private function mapUser(array $payload): LegacyDataBenchmarkUser
    {
        $address = $payload['address'] === null
            ? null
            : new LegacyDataBenchmarkAddress(
                $payload['address']['line_one'],
                $payload['address']['city'],
                $payload['address']['country_code'],
            );

        return new LegacyDataBenchmarkUser(
            $payload['id'],
            $payload['name'],
            $payload['email'],
            $payload['active'],
            $address,
        );
    }

    /**
     * Return the nearest-rank percentile from sorted samples.
     *
     * @param list<float> $samples
     */
    private function percentile(array $samples, float $percentile): float
    {
        $index = (int) ceil(count($samples) * $percentile) - 1;

        return $samples[max(0, min(count($samples) - 1, $index))];
    }

    /**
     * Return the reproducibility inputs for this run.
     *
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $commit = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));

        return [
            'timestamp' => gmdate(DATE_ATOM),
            'commit' => $commit !== '' ? $commit : 'unknown',
            'php' => PHP_VERSION,
            'os' => php_uname(),
            'extensions' => get_loaded_extensions(),
            'opcache_enabled' => ini_get('opcache.enable_cli') ?: '0',
            'jit' => ini_get('opcache.jit') ?: 'disabled',
            'operations_per_sample' => $this->operations,
            'samples' => $this->samples,
            'warmup_operations' => $this->warmup,
        ];
    }
}

/**
 * Run the benchmark CLI.
 */
function main(): int
{
    $options = getopt('', ['operations:', 'samples:', 'warmup:', 'json:', 'csv:', 'help']);

    if ($options === false) {
        fwrite(STDERR, "Unable to parse benchmark options.\n");

        return 1;
    }

    if (array_key_exists('help', $options)) {
        printUsage();

        return 0;
    }

    try {
        $operations = parseIntegerOption($options, 'operations', 20_000, 1, 1_000_000);
        $samples = parseIntegerOption($options, 'samples', 7, 1, 100);
        $warmup = parseIntegerOption($options, 'warmup', 1_000, 0, 100_000);
        $report = (new DataBenchmark($operations, $samples, $warmup))->execute();

        if (array_key_exists('json', $options)) {
            writeJsonReport($options['json'], $report);
        }

        if (array_key_exists('csv', $options)) {
            writeCsvReport($options['csv'], $report['results']);
        }
    } catch (Throwable $throwable) {
        fwrite(STDERR, sprintf("Benchmark failed: %s: %s\n", $throwable::class, $throwable->getMessage()));

        return 1;
    }

    return 0;
}

/**
 * Parse and validate one integer option.
 *
 * @param array<string, array<int, string>|false|string> $options
 */
function parseIntegerOption(array $options, string $name, int $default, int $minimum, int $maximum): int
{
    $value = $options[$name] ?? (string) $default;

    if (! is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException("--{$name} must be an integer.");
    }

    $value = (int) $value;

    if ($value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException("--{$name} must be between {$minimum} and {$maximum}.");
    }

    return $value;
}

/**
 * Write one JSON report.
 *
 * @param array<string, mixed> $report
 */
function writeJsonReport(mixed $path, array $report): void
{
    if (! is_string($path) || $path === '') {
        throw new InvalidArgumentException('--json must be a non-empty path.');
    }

    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
}

/**
 * Write one CSV report.
 *
 * @param list<array<string, float|int|string>> $results
 */
function writeCsvReport(mixed $path, array $results): void
{
    if (! is_string($path) || $path === '') {
        throw new InvalidArgumentException('--csv must be a non-empty path.');
    }

    $stream = fopen($path, 'wb');

    if ($stream === false) {
        throw new RuntimeException("Unable to open CSV report [{$path}].");
    }

    try {
        fputcsv($stream, array_keys($results[0]));

        foreach ($results as $result) {
            fputcsv($stream, $result);
        }
    } finally {
        fclose($stream);
    }
}

/**
 * Print command usage.
 */
function printUsage(): void
{
    echo <<<'TEXT'
Usage: php tests/Benchmarks/Data/benchmark.php [options]

Options:
  --operations=COUNT    Operations measured per sample (default: 20000)
  --samples=COUNT       Number of measured samples (default: 7)
  --warmup=COUNT        Unmeasured warmup operations (default: 1000)
  --json=PATH           Write the complete report as JSON
  --csv=PATH            Write scenario results as CSV
  --help                Show this help

TEXT;
}

exit(main());
