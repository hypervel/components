#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Http\Client\Factory;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

class MeasuredDatabasePool extends DatabasePool
{
    public array $acquisitions = [];

    public array $holds = [];

    private array $borrowedAt = [];

    /**
     * Measure checkout latency, including queueing and connection creation.
     */
    public function borrow(): PoolConnection
    {
        $started = hrtime(true);
        $connection = parent::borrow();
        $acquired = hrtime(true);
        $this->acquisitions[] = ($acquired - $started) / 1e6;
        $this->borrowedAt[spl_object_id($connection)] = $acquired;

        return $connection;
    }

    /**
     * Record the physical hold before returning the slot to waiting coroutines.
     */
    public function release(PoolConnection $connection): void
    {
        $identity = spl_object_id($connection);
        $this->holds[] = (hrtime(true) - $this->borrowedAt[$identity]) / 1e6;
        unset($this->borrowedAt[$identity]);
        parent::release($connection);
    }
}

/**
 * Summarize measured milliseconds without retaining per-request reports.
 */
function percentiles(array $values): array
{
    sort($values, SORT_NUMERIC);

    return [
        'p50' => $values[(int) ceil(count($values) * 0.5) - 1],
        'p95' => $values[(int) ceil(count($values) * 0.95) - 1],
    ];
}

$options = getopt('', ['requests:', 'concurrency:', 'queries:', 'samples:', 'wait-us:', 'http-url:', 'http-ca:', 'pool-size:', 'pool-metrics', 'release', 'transaction', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tests/Benchmarks/Database/benchmark.php [options]\n"
        . "  --requests=10000 --concurrency=32 --queries=10 --samples=7 --wait-us=0\n"
        . "  --pool-size=N  Default: concurrency; use a smaller pool to measure contention\n"
        . "  --pool-metrics Record checkout, hold and request latency percentiles\n"
        . "  --http-url=URL Replace the simulated wait with a real HTTP request\n"
        . "  --http-ca=PATH Trusted local CA for the HTTP origin\n"
        . "  --release      Release idle sessions before the simulated external wait\n"
        . "  --transaction  Wrap each request's queries in a transaction\n"
        . "Run measurements only in an owner-confirmed idle window. Output is JSON.\n";
    exit(0);
}

$settings = ['requests' => 10000, 'concurrency' => 32, 'queries' => 10, 'samples' => 7, 'wait-us' => 0];

foreach ($settings as $name => $default) {
    $value = filter_var($options[$name] ?? $default, FILTER_VALIDATE_INT);
    $minimum = in_array($name, ['queries', 'wait-us'], true) ? 0 : 1;

    if ($value === false || $value < $minimum) {
        throw new InvalidArgumentException("--{$name} must be an integer of at least {$minimum}.");
    }

    $settings[$name] = $value;
}

$release = isset($options['release']);
$transaction = isset($options['transaction']);
$poolMetrics = isset($options['pool-metrics']);
$httpUrl = $options['http-url'] ?? null;
$httpCertificate = $options['http-ca'] ?? true;
$settings['pool-size'] = filter_var($options['pool-size'] ?? $settings['concurrency'], FILTER_VALIDATE_INT);

if ($settings['pool-size'] === false || $settings['pool-size'] < 1) {
    throw new InvalidArgumentException('--pool-size must be an integer of at least 1.');
}

if ($release && ! method_exists(DatabaseManager::class, 'releaseIdleConnections')) {
    throw new RuntimeException('This checkout does not support early release; compare ordinary lifecycle scenarios against it.');
}

Bootstrapper::bootstrap();
$databasePath = tempnam(sys_get_temp_dir(), 'hypervel-database-benchmark-');

if ($databasePath === false) {
    throw new RuntimeException('Cannot create the benchmark database.');
}

$application = null;

try {
    $application = Application::create(options: ['load_environment_variables' => false]);
    $application->make('config')->set('database.connections.benchmark', [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'pool' => [
            'testing_enabled' => true,
            'min_retained_connections' => 0,
            'max_connections' => $settings['pool-size'],
            'heartbeat_interval' => null,
            'idle_check_interval' => null,
        ],
    ]);

    if ($poolMetrics) {
        $application->bind(DatabasePool::class, MeasuredDatabasePool::class);
    }

    $database = $application->make(DatabaseManager::class);
    $http = $httpUrl === null ? null : (new Factory)->registerConnection('benchmark');
    $pool = $application->make(PoolManager::class)->pool('benchmark');
    $reports = [];
    $failure = null;

    run(function () use ($database, $http, $httpUrl, $httpCertificate, $pool, $settings, $release, $transaction, $poolMetrics, &$reports, &$failure): void {
        try {
            for ($sample = -1; $sample < $settings['samples']; ++$sample) {
                if ($pool instanceof MeasuredDatabasePool) {
                    $pool->acquisitions = $pool->holds = [];
                }

                $latencies = [];
                // Collect between samples, never inside the measured request loop.
                gc_collect_cycles();
                memory_reset_peak_usage();
                $gcBefore = gc_status();
                $heapBefore = memory_get_usage();
                $cpuBefore = getrusage();
                $started = hrtime(true);
                $active = 0;
                $peakActive = 0;
                $peakBorrowed = 0;
                $peakRoots = $gcBefore['roots'];
                $resolutionNanoseconds = 0;
                $queryNanoseconds = 0;
                $cleanupNanoseconds = 0;
                $requests = $sample < 0 ? max(100, $settings['concurrency']) : $settings['requests'];

                $request = function () use (
                    $database,
                    $http,
                    $httpUrl,
                    $httpCertificate,
                    $pool,
                    $settings,
                    $release,
                    $transaction,
                    $poolMetrics,
                    &$latencies,
                    &$active,
                    &$peakActive,
                    &$peakBorrowed,
                    &$resolutionNanoseconds,
                    &$queryNanoseconds,
                    &$cleanupNanoseconds,
                ): void {
                    ++$active;
                    $peakActive = max($peakActive, $active);
                    $requestStarted = hrtime(true);
                    $cleanupStarted = 0;
                    // Registered first, so database release runs before this measurement.
                    Coroutine::defer(static function () use (&$active, &$cleanupStarted, &$cleanupNanoseconds, $requestStarted, $poolMetrics, &$latencies): void {
                        $cleanupNanoseconds += hrtime(true) - $cleanupStarted;

                        if ($poolMetrics) {
                            $latencies[] = (hrtime(true) - $requestStarted) / 1e6;
                        }

                        --$active;
                    });

                    try {
                        $resolving = hrtime(true);
                        $connection = $database->connection('benchmark');
                        $resolutionNanoseconds += hrtime(true) - $resolving;
                        $peakBorrowed = max($peakBorrowed, $pool->getBorrowedCount());

                        if ($transaction) {
                            $connection->beginTransaction();
                        }

                        for ($query = 0; $query < max(1, $settings['queries']); ++$query) {
                            if ($settings['queries'] > 0) {
                                $queryStarted = hrtime(true);
                                $connection->selectOne('select 1 as value');
                                $queryNanoseconds += hrtime(true) - $queryStarted;
                            }

                            if ($query === 0) {
                                if ($release) {
                                    $database->releaseIdleConnections();
                                }

                                if ($http !== null) {
                                    $http->connection('benchmark')->withOptions([
                                        'verify' => $httpCertificate,
                                        'proxy' => '',
                                        'timeout' => 5,
                                    ])->get($httpUrl)->throw()->body();
                                } elseif ($settings['wait-us'] > 0) {
                                    usleep($settings['wait-us']);
                                }
                            }
                        }

                        if ($transaction) {
                            $connection->commit();
                        }
                    } finally {
                        $cleanupStarted = hrtime(true);
                    }
                };

                for ($completed = 0; $completed < $requests; $completed += $settings['concurrency']) {
                    parallel(array_fill(0, min($settings['concurrency'], $requests - $completed), $request));
                    $peakRoots = max($peakRoots, gc_status()['roots']);
                }

                $elapsed = hrtime(true) - $started;
                $cpuAfter = getrusage();
                $gcAfter = gc_status();

                if ($sample >= 0) {
                    $reports[] = [
                        'requests_per_second' => $requests * 1e9 / $elapsed,
                        'cpu_microseconds_per_request' => (
                            ($cpuAfter['ru_utime.tv_sec'] + $cpuAfter['ru_stime.tv_sec'] - $cpuBefore['ru_utime.tv_sec'] - $cpuBefore['ru_stime.tv_sec']) * 1e6
                            + $cpuAfter['ru_utime.tv_usec'] + $cpuAfter['ru_stime.tv_usec'] - $cpuBefore['ru_utime.tv_usec'] - $cpuBefore['ru_stime.tv_usec']
                        ) / $requests,
                        'resolution_ns_per_request' => $resolutionNanoseconds / $requests,
                        'query_ns_per_request' => $queryNanoseconds / $requests,
                        'deferred_cleanup_ns_per_request' => $cleanupNanoseconds / $requests,
                        'peak_active_requests' => $peakActive,
                        'peak_borrowed_slots' => $peakBorrowed,
                        'borrowed_slots_after_sample' => $pool->getBorrowedCount(),
                        'heap_growth_bytes' => memory_get_usage() - $heapBefore,
                        'peak_heap_bytes' => memory_get_peak_usage(),
                        'gc_runs' => $gcAfter['runs'] - $gcBefore['runs'],
                        'gc_collected' => $gcAfter['collected'] - $gcBefore['collected'],
                        'gc_roots' => $gcAfter['roots'],
                        'peak_sampled_gc_roots' => $peakRoots,
                        'gc_collector_seconds' => $gcAfter['collector_time'] - $gcBefore['collector_time'],
                    ] + ($pool instanceof MeasuredDatabasePool ? [
                        'checkout_ms' => percentiles($pool->acquisitions),
                        'physical_hold_ms' => percentiles($pool->holds),
                        'request_ms' => percentiles($latencies),
                    ] : []);
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;
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
        'gc_enabled' => gc_enabled(),
        'settings' => $settings + ['release' => $release, 'transaction' => $transaction, 'pool-metrics' => $poolMetrics, 'http-url' => $httpUrl, 'http-ca' => $httpCertificate],
        'samples' => $reports,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    try {
        $application?->make(PoolManager::class)->purgeAll();
        $application?->terminate();
    } finally {
        unlink($databasePath);
    }
}
