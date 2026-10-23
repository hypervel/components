#!/usr/bin/env php
<?php

declare(strict_types=1);

use Hypervel\Coroutine\Waiter;
use Hypervel\Database\Pool\PoolManager as DatabasePools;
use Hypervel\Redis\Pool\PoolManager as RedisPools;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application;
use Hypervel\Tests\Benchmarks\QueueOutbox\Fixtures\OutboxBenchmarkJob;
use Hypervel\Tests\Benchmarks\QueueOutbox\Fixtures\OutboxWorkload;

use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/tests/bootstrap.php';

/**
 * Validate a numeric workload option.
 */
function integerOption(array $options, string $name, int $default, int $minimum = 1): int
{
    $value = filter_var($options[$name] ?? $default, FILTER_VALIDATE_INT);

    if ($value === false || $value < $minimum) {
        throw new InvalidArgumentException("--{$name} must be an integer of at least {$minimum}.");
    }

    return $value;
}

/**
 * Return materialized results only after the phase releases its resources.
 */
function inFreshCoroutine(Closure $callback): mixed
{
    return (new Waiter(-1))->wait($callback, waitForChildTermination: true);
}

/**
 * Run one sample in a new process, with setup and verification outside timing.
 */
function main(): void
{
    $options = getopt('', ['case:', 'target:', 'database-connection:', 'redis-connection:', 'jobs:', 'batch:', 'concurrency:', 'pool-size:', 'backlog:', 'leased:', 'payload-bytes:', 'warmup:', 'explain', 'help']);

    if (isset($options['help'])) {
        echo "Usage: php tests/Benchmarks/QueueOutbox/benchmark.php [options]\n"
            . "  --case=direct|stage|relay|claims  --target=redis|database (relay only)\n"
            . "  --database-connection=NAME (omit for temporary functional-only SQLite)\n"
            . "  --redis-connection=default --jobs=10000 --batch=100 --concurrency=1\n"
            . "  --pool-size=4 --backlog=10000 --leased=N (default: batch * concurrency)\n"
            . "  --payload-bytes=256 --warmup=100\n"
            . "  --explain (claims only; executing plans outside timing)\n"
            . "Use a disposable database and an idle machine for measurements. See README.md.\n";

        return;
    }

    $case = $options['case'] ?? 'stage';
    $target = $options['target'] ?? 'redis';

    if (! in_array($case, ['direct', 'stage', 'relay', 'claims'], true)
        || ! in_array($target, ['redis', 'database'], true)
        || ($target === 'database' && $case !== 'relay')
        || (isset($options['explain']) && $case !== 'claims')) {
        throw new InvalidArgumentException('Invalid case, target or explain combination; see --help.');
    }

    $settings = [
        'case' => $case,
        'target' => $target,
        'jobs' => integerOption($options, 'jobs', 10_000),
        'batch' => integerOption($options, 'batch', 100),
        'concurrency' => integerOption($options, 'concurrency', 1),
        'pool_size' => integerOption($options, 'pool-size', 4),
        'backlog' => integerOption($options, 'backlog', 10_000, 4),
        'payload_bytes' => integerOption($options, 'payload-bytes', 256, 0),
        'warmup' => integerOption($options, 'warmup', 100, 0),
        'cache_store' => 'worker-array',
    ];
    $settings['leased'] = integerOption($options, 'leased', $settings['batch'] * $settings['concurrency'], 0);

    if (in_array($case, ['relay', 'claims'], true) && $settings['batch'] > 1000) {
        throw new InvalidArgumentException('Relay and claim batches must not exceed the worker limit of 1000.');
    }

    Bootstrapper::bootstrap();
    $application = Application::create(options: ['load_environment_variables' => false]);
    $config = $application->make('config');
    $identifier = bin2hex(random_bytes(6));
    $queue = 'benchmark-outbox-' . $identifier;
    $source = $options['database-connection'] ?? 'benchmark';
    $redis = $options['redis-connection'] ?? 'default';
    $sqlite = null;
    $workload = null;
    $failure = null;
    $report = [];

    try {
        if ($case !== 'direct' && ! isset($options['database-connection'])) {
            $sqlite = tempnam(sys_get_temp_dir(), 'hypervel-outbox-benchmark-');

            if ($sqlite === false) {
                throw new RuntimeException('Could not create the temporary SQLite database.');
            }

            $config->set('database.connections.benchmark', ['driver' => 'sqlite', 'database' => $sqlite, 'prefix' => '', 'busy_timeout' => 5000]);
        }

        if ($case !== 'direct') {
            $connection = $config->array("database.connections.{$source}");
            $connection['pool'] = array_replace($connection['pool'] ?? [], [
                'testing_enabled' => true, 'max_connections' => $settings['pool_size'],
                'heartbeat_interval' => null, 'idle_check_interval' => null,
            ]);
            $config->set("database.connections.{$source}", $connection);
            $config->set('database.default', $source);
            $config->set('queue.outbox', ['databases' => [$source], 'table' => 'bench_outbox_' . $identifier, 'retry_after' => 3600]);
        }

        $redisConfig = $config->array("database.redis.{$redis}");
        $redisConfig['pool'] = array_replace($redisConfig['pool'] ?? [], ['max_connections' => $settings['pool_size'], 'heartbeat_interval' => null, 'idle_check_interval' => null]);
        $config->set("database.redis.{$redis}", $redisConfig);
        $config->set('cache.default', $settings['cache_store']);
        $config->set('queue.connections.benchmark', $target === 'redis'
            ? ['driver' => 'redis', 'connection' => $redis, 'queue' => $queue, 'retry_after' => 3600, 'block_for' => null, 'after_commit' => false]
            : ['driver' => 'database', 'connection' => $source, 'table' => 'bench_jobs_' . $identifier, 'queue' => $queue, 'retry_after' => 3600, 'after_commit' => false]);
        fwrite(STDERR, "Owned benchmark resources: queue={$queue}, tables=bench_outbox_{$identifier},bench_jobs_{$identifier}\n");

        $completed = run(function () use ($application, $settings, $source, $queue, $redis, $identifier, $options, &$workload, &$report, &$failure): void {
            $queues = $application->make('queue');
            $direct = static function (int $count) use ($queues, $settings, $queue): void {
                for ($offset = 0; $offset < $count; $offset += $settings['batch']) {
                    $jobs = [];

                    for ($index = 0; $index < min($settings['batch'], $count - $offset); ++$index) {
                        $jobs[] = new OutboxBenchmarkJob(str_repeat('x', $settings['payload_bytes']));
                    }

                    if ($settings['batch'] === 1) {
                        $queues->connection('benchmark')->push($jobs[0], queue: $queue);
                    } else {
                        $queues->connection('benchmark')->bulk($jobs, queue: $queue);
                    }
                }
            };

            try {
                if ($settings['case'] !== 'direct') {
                    $workload = new OutboxWorkload($application, $source, $queue, $identifier, $settings);
                    inFreshCoroutine(fn () => $workload->setUp());
                }

                $execute = $workload === null ? $direct : $workload->execute(...);

                if ($settings['warmup'] > 0) {
                    inFreshCoroutine(fn () => $workload?->prepare($settings['warmup']));
                    inFreshCoroutine(fn () => $execute($settings['warmup']));
                    inFreshCoroutine(function () use ($workload, $queues, $queue, $settings): void {
                        $workload?->verify($settings['warmup']);
                        if ($workload === null && $queues->connection('benchmark')->size($queue) !== $settings['warmup']) {
                            throw new RuntimeException('Direct warmup delivered an unexpected job count.');
                        }
                        $workload?->reset();
                        if ($workload === null) {
                            $queues->connection('benchmark')->clear($queue);
                        }
                    });
                }

                inFreshCoroutine(fn () => $workload?->prepare($settings['jobs']));
                $walStart = inFreshCoroutine(fn () => $workload?->walPosition());
                gc_collect_cycles();
                memory_reset_peak_usage();
                $heap = memory_get_usage();
                $gc = gc_status();
                $cpu = getrusage();
                $started = hrtime(true);
                $details = inFreshCoroutine(fn () => $execute($settings['jobs']));
                $elapsed = (hrtime(true) - $started) / 1e9;
                $cpuAfter = getrusage();
                $heapGrowth = memory_get_usage() - $heap;
                $peakHeap = memory_get_peak_usage();
                $gcAfter = gc_status();
                $walBytes = inFreshCoroutine(fn () => $workload?->walBytes($walStart));
                inFreshCoroutine(function () use ($workload, $queues, $queue, $settings): void {
                    $workload?->verify($settings['jobs']);
                    if ($workload === null && $queues->connection('benchmark')->size($queue) !== $settings['jobs']) {
                        throw new RuntimeException('Direct dispatch delivered an unexpected job count.');
                    }
                });

                $borrowed = [];
                foreach ([DatabasePools::class, RedisPools::class] as $manager) {
                    foreach ($application->make($manager)->getPools() as $name => $pool) {
                        $borrowed[$manager . ':' . $name] = $pool->getBorrowedCount();
                    }
                }
                if (array_sum($borrowed) !== 0) {
                    throw new RuntimeException('The workload retained a borrowed connection after completion.');
                }

                $report = [
                    'timestamp' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'swoole' => phpversion('swoole'),
                    'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
                    'settings' => $settings, 'database_connection' => $settings['case'] === 'direct' ? null : $source,
                    'redis_connection' => $redis, 'queue' => $queue,
                    'backend' => inFreshCoroutine(fn () => $workload?->backend()),
                    'redis' => $settings['target'] === 'redis' && in_array($settings['case'], ['direct', 'relay'], true)
                        ? inFreshCoroutine(function () use ($application, $redis): array {
                            $profile = $application->make('config')->array("database.redis.{$redis}");
                            $information = $application->make('redis')->connection($redis)->info('server');

                            return [
                                'version' => $information['redis_version'] ?? $information['Server']['redis_version'] ?? null,
                                'host' => $profile['host'] ?? null, 'port' => $profile['port'] ?? null,
                                'database' => $profile['database'] ?? null,
                            ];
                        }) : null,
                    'seconds' => $elapsed, 'jobs_per_second' => $settings['jobs'] / $elapsed,
                    'cpu_seconds' => ($cpuAfter['ru_utime.tv_sec'] + $cpuAfter['ru_stime.tv_sec'] - $cpu['ru_utime.tv_sec'] - $cpu['ru_stime.tv_sec'])
                        + ($cpuAfter['ru_utime.tv_usec'] + $cpuAfter['ru_stime.tv_usec'] - $cpu['ru_utime.tv_usec'] - $cpu['ru_stime.tv_usec']) / 1e6,
                    'heap_growth_bytes' => $heapGrowth, 'peak_heap_bytes' => $peakHeap,
                    'gc_runs' => $gcAfter['runs'] - $gc['runs'], 'gc_collected' => $gcAfter['collected'] - $gc['collected'],
                    'gc_roots' => $gcAfter['roots'], 'borrowed_after_sample' => $borrowed,
                    'cluster_workload_wal_bytes' => $walBytes, 'details' => $details,
                    'plans' => isset($options['explain']) ? inFreshCoroutine(fn () => $workload->explain()) : null,
                ];
            } catch (Throwable $exception) {
                $failure = $exception;
            } finally {
                try {
                    inFreshCoroutine(function () use ($workload, $queues, $queue, $settings): void {
                        try {
                            $workload?->tearDown();
                        } finally {
                            if ($settings['target'] === 'redis' && in_array($settings['case'], ['direct', 'relay'], true)) {
                                $queues->connection('benchmark')->clear($queue);
                                if ($queues->connection('benchmark')->size($queue) !== 0) {
                                    throw new RuntimeException('Benchmark queue cleanup failed.');
                                }
                            }
                        }
                    });
                } catch (Throwable $cleanupFailure) {
                    if ($failure !== null) {
                        fwrite(STDERR, 'Cleanup also failed: ' . $cleanupFailure . PHP_EOL);
                    }
                    $failure ??= $cleanupFailure;
                }
            }
        });

        if ($failure !== null) {
            throw $failure;
        }
        if (! $completed) {
            throw new RuntimeException('The benchmark coroutine did not complete.');
        }

        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        $application->terminate();
        if (is_string($sqlite)) {
            unlink($sqlite);
        }
    }
}

try {
    main();
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . PHP_EOL);
    exit(1);
}
