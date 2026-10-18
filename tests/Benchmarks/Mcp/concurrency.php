#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Http\Client\Factory;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Symfony\Component\Process\Process;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = getopt('', ['samples:', 'rounds:', 'delay-ms:', 'smoke', 'idle-confirmed', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tests/Benchmarks/Mcp/concurrency.php --idle-confirmed [--samples=5 --rounds=25 --delay-ms=25]\n"
        . "Use --smoke for a short correctness run without performance conclusions. Output is JSON.\n";
    exit(0);
}

$smoke = isset($options['smoke']);
if (! $smoke && ! isset($options['idle-confirmed'])) {
    throw new InvalidArgumentException('Confirm an idle measurement window before passing --idle-confirmed.');
}

$settings = ['samples' => $smoke ? 1 : 5, 'rounds' => $smoke ? 2 : 25, 'delay-ms' => 25];
foreach ($settings as $name => $default) {
    $value = filter_var($options[$name] ?? $default, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        throw new InvalidArgumentException("--{$name} must be a positive integer.");
    }
    $settings[$name] = $value;
}

/**
 * Summarize observed durations without interpolating samples.
 */
function durations(array $values): array
{
    sort($values, SORT_NUMERIC);

    return [
        'p50' => $values[(int) ceil(count($values) * 0.5) - 1],
        'p95' => $values[(int) ceil(count($values) * 0.95) - 1],
        'max' => $values[count($values) - 1],
    ];
}

/**
 * Read CPU consumed by this client process, excluding the mock server.
 */
function cpuMicroseconds(): int
{
    $usage = getrusage();

    return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000
        + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
}

Bootstrapper::bootstrap();
$application = Application::create(options: [
    'load_environment_variables' => false,
    'extra' => ['dont-discover' => ['*']],
]);
$process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/server.php']);
$process->setTimeout(10);
$reports = [];
$failure = null;

run(function () use ($application, $process, $settings, &$reports, &$failure): void {
    $client = null;

    try {
        $process->start();
        $process->waitUntil(fn (): bool => str_contains($process->getOutput(), "\n"));
        if (preg_match('/^READY (\d+)$/m', $process->getOutput(), $matches) !== 1) {
            throw new RuntimeException('The MCP benchmark server did not start: ' . $process->getErrorOutput());
        }
        // The process timeout bounds startup; measured requests have their own timeout.
        $process->setTimeout(null);
        $endpoint = 'http://127.0.0.1:' . $matches[1];
        $client = Client::web($endpoint)->withTimeout(5)->withProtocolVersion(ProtocolVersion::LATEST);
        $client->connect();

        for ($sample = -1; $sample < $settings['samples']; ++$sample) {
            // Alternate case order and warm both paths before retaining measurements.
            foreach ($sample % 2 === 0 ? [true, false] : [false, true] as $concurrent) {
                gc_collect_cycles();
                memory_reset_peak_usage();
                $heapBefore = memory_get_usage();
                $calls = [];
                $groups = [];
                $peakActive = 0;
                $cpuBefore = cpuMicroseconds();
                $started = hrtime(true);
                $rounds = $sample < 0 ? 1 : $settings['rounds'];

                for ($round = 0; $round < $rounds; ++$round) {
                    $operations = [];
                    foreach (['first', 'second', 'third', 'fourth'] as $name) {
                        $operations[] = static function () use ($client, $settings, $name, &$calls): array {
                            $started = hrtime(true);
                            $result = $client->callTool($name, ['delay_ms' => $settings['delay-ms']]);
                            $calls[] = (hrtime(true) - $started) / 1e6;

                            return [$result->text(), $result->meta['active']];
                        };
                    }
                    $groupStarted = hrtime(true);
                    $results = $concurrent
                        ? parallel($operations)
                        : array_map(static fn (Closure $operation): array => $operation(), $operations);
                    $groups[] = (hrtime(true) - $groupStarted) / 1e6;

                    if (array_column($results, 0) !== ['first', 'second', 'third', 'fourth']) {
                        throw new RuntimeException('MCP replies did not match their calls.');
                    }
                    $peakActive = max($peakActive, ...array_column($results, 1));
                }

                $elapsed = (hrtime(true) - $started) / 1e9;
                $cpu = cpuMicroseconds() - $cpuBefore;
                $report = [
                    'mode' => $concurrent ? 'concurrent' : 'sequential',
                    'sample' => $sample + 1,
                    'calls' => $rounds * 4,
                    'elapsed_seconds' => $elapsed,
                    'calls_per_second' => $rounds * 4 / $elapsed,
                    'client_cpu_us_per_call' => $cpu / ($rounds * 4),
                    'call_ms' => durations($calls),
                    'four_call_group_ms' => durations($groups),
                    'peak_server_calls' => $peakActive,
                    'heap_before_bytes' => $heapBefore,
                    'heap_after_bytes' => memory_get_usage(),
                    'peak_heap_bytes' => memory_get_peak_usage(),
                ];

                $response = $application->make(Factory::class)->timeout(5)->get($endpoint . '/active');
                try {
                    if ($response->throw()->json('active') !== 0 || $peakActive !== ($concurrent ? 4 : 1)) {
                        throw new RuntimeException('The fixture did not observe the expected overlap and completion.');
                    }
                } finally {
                    $response->close();
                }

                if ($sample >= 0) {
                    $reports[] = $report;
                }
            }
        }
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        try {
            $client?->disconnect();
        } finally {
            $process->stop(0);
        }
    }
});

if ($failure !== null) {
    throw $failure;
}

echo json_encode([
    'php' => PHP_VERSION,
    'swoole' => swoole_version(),
    'opcache_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'smoke' => $smoke,
    'settings' => $settings,
    'samples' => $reports,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
