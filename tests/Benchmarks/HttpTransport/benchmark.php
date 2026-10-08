#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Response;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = getopt('', ['endpoint:', 'ca:', 'requests:', 'concurrency:', 'samples:', 'chunks:', 'bytes:', 'pace-us:', 'burst-gap-us:', 'consumer-us:', 'unnamed', 'buffered', 'bytewise', 'help']);

if (isset($options['help'])) {
    echo "Usage: php benchmark.php --endpoint=https://127.0.0.1:PORT --ca=/path/to/certificate.pem [options]\n"
        . "  --requests=256 --concurrency=8 --samples=5 --chunks=32 --bytes=128\n"
        . "  --pace-us=1000 --burst-gap-us=5000 --consumer-us=0 --unnamed --buffered --bytewise\n"
        . "Run only in an owner-confirmed idle window. Output is JSON.\n";
    exit(0);
}

$endpoint = $options['endpoint'] ?? '';
$certificate = $options['ca'] ?? '';

if (! str_starts_with($endpoint, 'https://') || ! is_file($certificate)) {
    throw new InvalidArgumentException('Supply an HTTPS endpoint and its trusted local CA certificate.');
}

$settings = ['requests' => 256, 'concurrency' => 8, 'samples' => 5, 'chunks' => 32, 'bytes' => 128, 'pace-us' => 1000, 'burst-gap-us' => 5000, 'consumer-us' => 0];

foreach ($settings as $name => $default) {
    $value = filter_var($options[$name] ?? $default, FILTER_VALIDATE_INT);
    $minimum = str_ends_with($name, '-us') ? 0 : ($name === 'bytes' ? 8 : 1);

    if ($value === false || $value < $minimum) {
        throw new InvalidArgumentException("--{$name} must be an integer of at least {$minimum}.");
    }

    $settings[$name] = $value;
}

/**
 * Return latency percentiles in milliseconds.
 */
function percentiles(array $values): array
{
    sort($values, SORT_NUMERIC);

    return [
        'p50' => $values[(int) ceil(count($values) * 0.5) - 1],
        'p95' => $values[(int) ceil(count($values) * 0.95) - 1],
    ];
}

/**
 * Read current Linux process resources without including the origin process.
 */
function resources(): array
{
    $status = file_get_contents('/proc/self/status');
    preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $resident);

    return [
        'heap_bytes' => memory_get_usage(),
        'rss_bytes' => (int) $resident[1] * 1024,
        'open_fds' => count(scandir('/proc/self/fd')) - 2,
    ];
}

/**
 * Reproduce the byte-at-a-time reader used to avoid StreamHandler batching.
 */
function bytewiseLines(Response $response): Generator
{
    $stream = $response->toPsrResponse()->getBody();
    $line = '';

    while (! $stream->eof()) {
        $byte = $stream->read(1);

        if ($byte === "\n") {
            yield $line;
            $line = '';
        } else {
            $line .= $byte;
        }
    }

    if ($line !== '') {
        yield $line;
    }
}

$factory = (new Factory)->registerConnection('benchmark');
$named = ! isset($options['unnamed']);
$buffered = isset($options['buffered']);
$bytewise = isset($options['bytewise']);
$seenConnections = [];
$reports = [];
$cold = null;
$failure = null;
$before = resources();

run(function () use ($factory, $endpoint, $certificate, $settings, $named, $buffered, $bytewise, &$seenConnections, &$reports, &$cold, &$failure): void {
    try {
        for ($sample = -1; $sample < $settings['samples']; ++$sample) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = resources();
            $cpuBefore = getrusage();
            $gcBefore = gc_status();
            $started = hrtime(true);
            $active = 0;
            $peakActive = 0;
            $peakOriginActive = 0;
            $connections = [];
            $newConnections = 0;
            $firstEvents = [];
            $latencies = [];
            $requests = $sample < 0 ? $settings['concurrency'] : $settings['requests'];

            $request = function () use ($factory, $endpoint, $certificate, $settings, $named, $buffered, $bytewise, &$active, &$peakActive, &$peakOriginActive, &$connections, &$newConnections, &$seenConnections, &$firstEvents, &$latencies): void {
                $started = hrtime(true);
                ++$active;
                $peakActive = max($peakActive, $active);
                $response = null;

                try {
                    $pending = $named ? $factory->connection('benchmark') : $factory->withOptions([]);
                    $response = $pending->withToken(bin2hex(random_bytes(16)))->withOptions([
                        'stream' => ! $buffered,
                        'verify' => $certificate,
                        'proxy' => '',
                        'version' => '1.1',
                        'timeout' => 10,
                        'read_timeout' => 10,
                    ])->get($endpoint, [
                        'chunks' => $settings['chunks'],
                        'bytes' => $settings['bytes'],
                        'pace_us' => $settings['pace-us'],
                    ])->throw();
                    $identity = $response->header('X-Benchmark-Connection');
                    $connections[$identity] = true;
                    $newConnections += (int) ! isset($seenConnections[$identity]);
                    $seenConnections[$identity] = true;
                    $peakOriginActive = max($peakOriginActive, (int) $response->header('X-Benchmark-Active'));
                    $events = 0;

                    foreach ($bytewise ? bytewiseLines($response) : $response->lines() as $line) {
                        if ($line === '') {
                            continue;
                        }

                        if ($events++ === 0) {
                            $firstEvents[] = (hrtime(true) - $started) / 1e6;
                        }

                        if (strlen($line) !== $settings['bytes'] - 2) {
                            throw new RuntimeException('The origin returned an incomplete event.');
                        }

                        if ($settings['consumer-us'] > 0) {
                            usleep($settings['consumer-us']);
                        }
                    }

                    if ($events !== $settings['chunks']) {
                        throw new RuntimeException('The origin returned an unexpected event count.');
                    }
                } finally {
                    $response?->close();
                    --$active;
                    $latencies[] = (hrtime(true) - $started) / 1e6;
                }
            };

            for ($completed = 0; $completed < $requests; $completed += $settings['concurrency']) {
                parallel(array_fill(0, min($settings['concurrency'], $requests - $completed), $request));

                if ($settings['burst-gap-us'] > 0 && $completed + $settings['concurrency'] < $requests) {
                    usleep($settings['burst-gap-us']);
                }
            }

            $elapsed = hrtime(true) - $started;
            $cpuAfter = getrusage();
            $gcAfter = gc_status();
            $report = [
                'requests_per_second' => $requests * 1e9 / $elapsed,
                'cpu_microseconds_per_request' => (
                    ($cpuAfter['ru_utime.tv_sec'] + $cpuAfter['ru_stime.tv_sec'] - $cpuBefore['ru_utime.tv_sec'] - $cpuBefore['ru_stime.tv_sec']) * 1e6
                    + $cpuAfter['ru_utime.tv_usec'] + $cpuAfter['ru_stime.tv_usec'] - $cpuBefore['ru_utime.tv_usec'] - $cpuBefore['ru_stime.tv_usec']
                ) / $requests,
                'first_event_ms' => percentiles($firstEvents),
                'request_ms' => percentiles($latencies),
                'peak_active_requests' => $peakActive,
                'peak_origin_requests' => $peakOriginActive,
                'origin_connections' => count($connections),
                'new_origin_connections' => $newConnections,
                'resources_before' => $before,
                'resources_after' => resources(),
                'peak_heap_bytes' => memory_get_peak_usage(),
                'gc_runs' => $gcAfter['runs'] - $gcBefore['runs'],
                'gc_collected' => $gcAfter['collected'] - $gcBefore['collected'],
                'gc_roots' => $gcAfter['roots'],
            ];

            if ($sample < 0) {
                $cold = $report;
            } else {
                $reports[] = $report;
            }
        }
    } catch (Throwable $exception) {
        $failure = $exception;
    }
}, SWOOLE_HOOK_ALL);

$factory->forgetConnectionHandlers();
gc_collect_cycles();

if ($failure !== null) {
    throw $failure;
}

echo json_encode([
    'php' => PHP_VERSION,
    'swoole' => swoole_version(),
    'opcache_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'gc_enabled' => gc_enabled(),
    'settings' => $settings + ['named' => $named, 'buffered' => $buffered, 'bytewise' => $bytewise],
    'cold' => $cold,
    'samples' => $reports,
    'resources_before' => $before,
    'resources_after_cleanup' => resources(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
