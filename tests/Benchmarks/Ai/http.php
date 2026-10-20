#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\TransferStats;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Context\CoroutineContext;
use Hypervel\Http\Client\Factory;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$options = getopt('', ['endpoint:', 'ca:', 'requests:', 'concurrency:', 'samples:', 'chunks:', 'bytes:', 'pace-us:', 'burst-gap-us:', 'consumer-us:', 'accounts:', 'http-only', 'buffered', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tests/Benchmarks/Ai/http.php --endpoint=https://127.0.0.1:PORT --ca=/path/to/certificate.pem [options]\n"
        . "  --requests=256 --concurrency=8 --samples=5 --chunks=32 --bytes=128\n"
        . "  --pace-us=1000 --burst-gap-us=5000 --consumer-us=0 --accounts=0 --http-only --buffered\n"
        . "Run measurements on an idle machine. Output is JSON.\n";
    exit(0);
}

$endpoint = $options['endpoint'] ?? '';
$certificate = $options['ca'] ?? '';

if (! str_starts_with($endpoint, 'https://') || ! is_file($certificate)) {
    throw new InvalidArgumentException('Supply an HTTPS endpoint and its trusted local CA certificate.');
}

$settings = ['requests' => 256, 'concurrency' => 8, 'samples' => 5, 'chunks' => 32, 'bytes' => 128, 'pace-us' => 1000, 'burst-gap-us' => 5000, 'consumer-us' => 0, 'accounts' => 0];

foreach ($settings as $name => $default) {
    $value = filter_var($options[$name] ?? $default, FILTER_VALIDATE_INT);
    $minimum = str_ends_with($name, '-us') || $name === 'accounts' ? 0 : 1;

    if ($value === false || $value < $minimum) {
        throw new InvalidArgumentException("--{$name} must be an integer of at least {$minimum}.");
    }

    $settings[$name] = $value;
}

$buffered = isset($options['buffered']);
$httpOnly = isset($options['http-only']);

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

Bootstrapper::bootstrap();
$application = Application::create(options: [
    'load_environment_variables' => false,
    'extra' => ['providers' => [AiServiceProvider::class], 'dont-discover' => ['*']],
]);
$application->make('config')->set('ai.providers.openai', [
    'driver' => 'openai',
    'key' => 'benchmark-0',
    'url' => $endpoint,
]);
$factory = $application->make(Factory::class);
$manager = $application->make(AiManager::class);

if ($settings['accounts'] > 0) {
    $manager->resolveProviderConfigUsing(static fn (string $name, array $config): array => array_replace($config, [
        'key' => CoroutineContext::get('__ai_benchmark.key'),
    ]));
}

$lastConnection = 0;
$connections = [];
$peakOriginActive = 0;
$ordinal = 0;
$factory->registerConnection(AiManager::HTTP_CONNECTION . '.openai', [
    'verify' => $certificate,
    'proxy' => '',
    'version' => '1.1',
    'timeout' => 10,
    'read_timeout' => 10,
    'query' => [
        'chunks' => $settings['chunks'],
        'bytes' => $settings['bytes'],
        'pace_us' => $settings['pace-us'],
    ],
    'on_stats' => static function (TransferStats $stats) use (&$connections, &$peakOriginActive): void {
        $response = $stats->getResponse();

        if ($response === null) {
            return;
        }

        $expected = hash('sha256', 'Bearer ' . CoroutineContext::get('__ai_benchmark.key'));

        if ($response->getHeaderLine('X-Benchmark-Account') !== $expected) {
            throw new RuntimeException('The origin received another account\'s credentials.');
        }

        $connections[(int) $response->getHeaderLine('X-Benchmark-Connection')] = true;
        $peakOriginActive = max($peakOriginActive, (int) $response->getHeaderLine('X-Benchmark-Active'));
    },
]);
$reports = [];
$cold = null;
$failure = null;
$before = resources();

run(function () use ($factory, $endpoint, $settings, $buffered, $httpOnly, &$lastConnection, &$connections, &$peakOriginActive, &$ordinal, &$reports, &$cold, &$failure): void {
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
            $firstEvents = [];
            $firstText = [];
            $latencies = [];
            $burstStarted = 0;
            $requests = $sample < 0 ? $settings['concurrency'] : $settings['requests'];

            $request = function () use ($factory, $endpoint, $settings, $buffered, $httpOnly, &$ordinal, &$active, &$peakActive, &$firstEvents, &$firstText, &$latencies, &$burstStarted): void {
                // All requests in a burst arrive together; include coroutine admission in latency.
                $started = $burstStarted;
                ++$active;
                $peakActive = max($peakActive, $active);
                $response = null;
                $account = $settings['accounts'] === 0 ? 0 : $ordinal++ % $settings['accounts'];
                $key = 'benchmark-' . $account;
                CoroutineContext::set('__ai_benchmark.key', $key);

                try {
                    $events = 0;
                    $text = '';

                    if ($httpOnly) {
                        $response = $factory->connection(AiManager::HTTP_CONNECTION . '.openai')
                            ->withToken($key)->withOptions(['stream' => ! $buffered])
                            ->post(rtrim($endpoint, '/') . '/responses', [
                                'model' => 'gpt-5.4',
                                'input' => [['role' => 'user', 'content' => 'Hello']],
                                'stream' => ! $buffered,
                            ])->throw();

                        if ($buffered) {
                            $text = $response->json('output.0.content.0.text');
                        } else {
                            foreach ($response->lines() as $line) {
                                if (! str_starts_with($line, 'data: ')) {
                                    continue;
                                }

                                $data = json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);

                                if ($events++ === 0) {
                                    $firstEvents[] = (hrtime(true) - $started) / 1e6;
                                }

                                if ($data['type'] === 'response.output_text.delta') {
                                    if ($text === '') {
                                        $firstText[] = (hrtime(true) - $started) / 1e6;
                                    }

                                    $text .= $data['delta'];

                                    if ($settings['consumer-us'] > 0) {
                                        usleep($settings['consumer-us']);
                                    }
                                }
                            }
                        }
                    } elseif ($buffered) {
                        $text = (new OpenAiAgent)->prompt('Hello', model: 'gpt-5.4', timeout: 10)->text;
                    } else {
                        $stream = (new OpenAiAgent)->stream('Hello', model: 'gpt-5.4', timeout: 10);

                        foreach ($stream as $event) {
                            if ($events++ === 0) {
                                $firstEvents[] = (hrtime(true) - $started) / 1e6;
                            }

                            if ($event instanceof TextDelta) {
                                if ($text === '') {
                                    $firstText[] = (hrtime(true) - $started) / 1e6;
                                }

                                $text .= $event->delta;

                                if ($settings['consumer-us'] > 0) {
                                    usleep($settings['consumer-us']);
                                }
                            }
                        }

                        if ($stream->text !== $text) {
                            throw new RuntimeException('The completed response differs from its streamed text.');
                        }
                    }

                    if ($text !== str_repeat('x', $settings['chunks'] * $settings['bytes'])) {
                        throw new RuntimeException('The origin returned incomplete text.');
                    }
                } finally {
                    $response?->close();
                    --$active;
                    $latencies[] = (hrtime(true) - $started) / 1e6;
                }
            };

            for ($completed = 0; $completed < $requests; $completed += $settings['concurrency']) {
                $burstStarted = hrtime(true);
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
                'first_event_ms' => $buffered ? null : percentiles($firstEvents),
                'first_text_ms' => $buffered ? null : percentiles($firstText),
                'request_ms' => percentiles($latencies),
                'peak_active_operations' => $peakActive,
                'peak_origin_requests' => $peakOriginActive,
                'origin_connections' => count($connections),
                'new_origin_connections' => count(array_filter(array_keys($connections), static fn (int $identity): bool => $identity > $lastConnection)),
                'resources_before' => $before,
                'resources_after' => resources(),
                'peak_heap_bytes' => memory_get_peak_usage(),
                'gc_runs' => $gcAfter['runs'] - $gcBefore['runs'],
                'gc_collected' => $gcAfter['collected'] - $gcBefore['collected'],
                'gc_roots' => $gcAfter['roots'],
            ];
            $lastConnection = max($lastConnection, ...array_keys($connections));

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
$application->terminate();
gc_collect_cycles();

if ($failure !== null) {
    fwrite(STDERR, (string) $failure . PHP_EOL);
    exit(1);
}

echo json_encode([
    'php' => PHP_VERSION,
    'swoole' => swoole_version(),
    'opcache_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'gc_enabled' => gc_enabled(),
    'settings' => $settings + ['http_only' => $httpOnly, 'buffered' => $buffered],
    'cold' => $cold,
    'samples' => $reports,
    'resources_before' => $before,
    'resources_after_cleanup' => resources(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
