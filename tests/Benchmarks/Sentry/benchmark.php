#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Sentry HTTP and transport-pool benchmark. See README.md for setup and methodology.
 *
 *   php benchmark.php launch APP CONFIG --workspace=DIR [--smoke]
 *   php benchmark.php saturation APP --workspace=DIR [--smoke]
 *   php benchmark.php matrix --workspace=DIR [--repetitions=3] [--smoke]
 *
 * APP is baseline or current; CONFIG is C0..C5. Results are JSON files under results/.
 */

const PHP_FLAGS = ['-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=disable'];
const APP_KEY = 'base64:bqnTvhYvPuYO87TxS/VbTFjhVQHCaPRRtONi0BnBBxE=';
const QUIESCENCE_POLLS = 3;
const QUIESCENCE_INTERVAL = 0.05;
const QUIESCENCE_DEADLINE = 30.0;

const WORKLOADS = [
    'plain32' => ['path' => '/plain', 'concurrency' => 32, 'warmup' => 2000, 'requests' => 20000, 'expect' => 'plain-ok', 'transaction' => '/plain'],
    'work32' => ['path' => '/work', 'concurrency' => 32, 'warmup' => 1000, 'requests' => 10000, 'expect' => 'bench-ok', 'transaction' => '/work'],
    'work1' => ['path' => '/work', 'concurrency' => 1, 'warmup' => 300, 'requests' => 3000, 'expect' => 'bench-ok', 'transaction' => '/work'],
];

const SMOKE_WORKLOAD = ['warmup' => 10, 'requests' => 40];

const SATURATION = ['path' => '/work?report=1', 'concurrency' => 64, 'warmup' => 1000, 'wave' => 5000, 'delay_ms' => 200, 'expect' => 'bench-ok'];

const SMOKE_SATURATION = ['warmup' => 10, 'wave' => 20];

const TOGGLES_OFF = [
    'SENTRY_BREADCRUMBS_LOGS_ENABLED', 'SENTRY_BREADCRUMBS_CACHE_ENABLED', 'SENTRY_BREADCRUMBS_STORAGE_ENABLED',
    'SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', 'SENTRY_BREADCRUMBS_SQL_TRANSACTIONS_ENABLED',
    'SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', 'SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED',
    'SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', 'SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED',
    'SENTRY_TRACE_QUEUE_ENABLED', 'SENTRY_TRACE_QUEUE_JOBS_ENABLED', 'SENTRY_TRACE_SQL_QUERIES_ENABLED',
    'SENTRY_TRACE_VIEWS_ENABLED', 'SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', 'SENTRY_TRACE_CACHE_ENABLED',
    'SENTRY_TRACE_STORAGE_ENABLED', 'SENTRY_TRACE_NOTIFICATIONS_ENABLED',
];

/**
 * Return the environment for one configuration, replacing the inherited one entirely.
 */
function environment(string $app, string $config): array
{
    $environment = [
        'PATH' => getenv('PATH'),
        'HOME' => getenv('HOME'),
        'APP_NAME' => 'Hypervel',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => APP_KEY,
        'APP_URL' => 'http://127.0.0.1:' . APP_PORT,
        'LOG_CHANNEL' => 'null',
        'LOG_LEVEL' => 'debug',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ROOT . "/app-{$app}/database/bench.sqlite",
        'SESSION_DRIVER' => 'array',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SERVER_HOST' => '127.0.0.1',
        'SERVER_PORT' => (string) APP_PORT,
        'BENCH_STATS_PORT' => (string) STATS_PORT,
        'SERVER_WORKERS' => '1',
        'SERVER_MAX_REQUESTS' => '0',
    ];

    if (in_array($config, ['C2', 'C3', 'C4', 'C5'], true)) {
        $environment['SENTRY_DSN'] = 'http://public@127.0.0.1:' . SINK_PORT . '/1';
    }

    $environment += match ($config) {
        'C3' => ['SENTRY_TRACES_SAMPLE_RATE' => '0'],
        'C4' => ['SENTRY_TRACES_SAMPLE_RATE' => '1.0'],
        'C5' => ['SENTRY_TRACES_SAMPLE_RATE' => '1.0'] + array_fill_keys(TOGGLES_OFF, 'false'),
        default => [],
    };

    return $environment;
}

/**
 * Run a command, failing on a non-zero exit.
 */
function command(array $command, string $cwd, ?array $environment = null): string
{
    // Merge stderr into stdout so a full error pipe cannot block the child.
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException(implode(' ', $command) . " failed ({$status}):\n{$output}");
    }

    return $output;
}

/**
 * Build the app's caches and package manifest for one configuration.
 */
function prepare(string $app, string $config): void
{
    $directory = ROOT . "/app-{$app}";
    $environment = environment($app, $config);
    $ignore = $config === 'C0' ? '["hypervel/sentry"]' : '[]';

    // Clear the previous configuration's cache first: it lacks the Sentry config when Sentry was not discovered.
    command([PHP_BINARY, 'artisan', 'config:clear'], $directory, $environment);
    command(['composer', 'config', 'extra.hypervel.dont-discover', '--json', $ignore], $directory);
    command([PHP_BINARY, 'artisan', 'package:discover'], $directory, $environment);
    command([PHP_BINARY, 'artisan', 'config:cache'], $directory, $environment);
    command([PHP_BINARY, 'artisan', 'route:cache'], $directory, $environment);
    command([PHP_BINARY, 'artisan', 'view:cache'], $directory, $environment);

    $providers = require "{$directory}/bootstrap/cache/packages.php";
    $discovered = isset($providers['hypervel/sentry']);

    if ($discovered === ($config === 'C0')) {
        throw new RuntimeException("Sentry discovery does not match {$config}.");
    }
}

/**
 * Fetch JSON over HTTP.
 */
function fetch(string $url): ?array
{
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));

    return $body === false ? null : json_decode($body, true);
}

/**
 * Read the worker's state from the stats listener.
 */
function stats(bool $resetPeak = false): array
{
    return fetch('http://127.0.0.1:' . STATS_PORT . '/stats' . ($resetPeak ? '?reset_peak=1' : ''))
        ?? throw new RuntimeException('Stats listener unavailable.');
}

/**
 * Read the sink's counts.
 */
function sink(string $path = '/stats'): array
{
    return fetch('http://127.0.0.1:' . SINK_PORT . $path) ?? throw new RuntimeException('Sink unavailable.');
}

/**
 * Apply optional CPU affinity without requiring taskset on unpinned runs.
 */
function pin(array $command, ?string $cpus): array
{
    return $cpus === null ? $command : ['taskset', '-c', $cpus, ...$command];
}

/**
 * Refuse to reuse a process that this run does not own.
 */
function requireFreePort(int $port): void
{
    $socket = @fsockopen('127.0.0.1', $port, timeout: 0.2);

    if ($socket !== false) {
        fclose($socket);
        throw new RuntimeException("Port {$port} is already in use.");
    }
}

/**
 * Start an owned telemetry sink and wait for it to accept requests.
 *
 * @return array{resource, int}
 */
function startSink(string $log): array
{
    requireFreePort(SINK_PORT);
    $process = proc_open(
        pin([PHP_BINARY, ...PHP_FLAGS, __DIR__ . '/Fixtures/sink.php', (string) SINK_PORT], SINK_CPUS),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
    );
    $pid = proc_get_status($process)['pid'];
    $deadline = microtime(true) + 10;

    try {
        while (fetch('http://127.0.0.1:' . SINK_PORT . '/stats') === null) {
            if (! proc_get_status($process)['running'] || microtime(true) > $deadline) {
                throw new RuntimeException('Sink did not start: ' . @file_get_contents($log));
            }

            usleep(50000);
        }
    } catch (Throwable $exception) {
        stopServer($process, $pid);
        throw $exception;
    }

    return [$process, $pid];
}

/**
 * Start the app server and wait for its stats listener.
 *
 * @return array{0: resource, 1: int}
 */
function startServer(string $app, string $config, string $log): array
{
    foreach ([APP_PORT, STATS_PORT] as $port) {
        requireFreePort($port);
    }

    $process = proc_open(
        pin([PHP_BINARY, ...PHP_FLAGS, 'artisan', 'serve'], SERVER_CPUS),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        ROOT . "/app-{$app}",
        environment($app, $config),
    );
    $master = proc_get_status($process)['pid'];
    $deadline = microtime(true) + 30;

    try {
        while (fetch('http://127.0.0.1:' . STATS_PORT . '/stats') === null) {
            if (! proc_get_status($process)['running'] || microtime(true) > $deadline) {
                throw new RuntimeException("Server did not start:\n" . @file_get_contents($log));
            }

            usleep(50000);
        }
    } catch (Throwable $exception) {
        stopServer($process, $master);
        throw $exception;
    }

    return [$process, $master];
}

/**
 * Stop an owned server and reap its process.
 *
 * @param resource $process
 */
function stopServer($process, int $master): void
{
    if (proc_get_status($process)['running']) {
        posix_kill($master, SIGTERM);
    }
    $deadline = microtime(true) + 15;

    while (proc_get_status($process)['running']) {
        if (microtime(true) > $deadline) {
            posix_kill($master, SIGKILL);
        }

        usleep(50000);
    }

    proc_close($process);
}

/**
 * Sum the CPU seconds of the server's process tree.
 */
function serverCpu(int $master): float
{
    $parents = [];
    $ticks = [];

    foreach (glob('/proc/[0-9]*/stat') as $file) {
        $stat = @file_get_contents($file);

        if ($stat === false) {
            continue;
        }

        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        $pid = (int) basename(dirname($file));
        $parents[$pid] = (int) $fields[1];
        $ticks[$pid] = (int) $fields[11] + (int) $fields[12];
    }

    $total = 0;
    $tree = [$master];

    while ($tree !== []) {
        $pid = array_pop($tree);
        $total += $ticks[$pid] ?? 0;

        foreach ($parents as $child => $parent) {
            if ($parent === $pid) {
                $tree[] = $child;
            }
        }
    }

    return $total / CLOCK_TICKS;
}

/**
 * Wait until deliveries, coroutines and the sink have settled.
 *
 * With $coroutines, the worker must also return to that coroutine count.
 */
function quiescence(?int $coroutines = null): array
{
    $started = microtime(true);
    $streak = [];
    $previousSink = null;
    $last = null;

    while (true) {
        $worker = stats();
        $counts = sink();
        $sentry = $worker['sentry'];
        $deliveries = $sentry === null || ! isset($sentry['borrowed'])
            || ($sentry['borrowed'] === 0 && $sentry['waiting'] === 0 && $sentry['pending_sends'] === 0);
        $sinkSettled = $previousSink !== null
            && $counts['envelopes'] === $previousSink['envelopes']
            && $counts['in_flight'] === 0;
        $coroutinesSettled = $streak === []
            ? true
            : $worker['coroutines'] === $streak[0]['worker']['coroutines'];
        $coroutinesSettled = $coroutinesSettled && ($coroutines === null || $worker['coroutines'] <= $coroutines);
        $last = ['worker' => $worker, 'sink' => $counts, 'at' => microtime(true)];
        $previousSink = $counts;

        if ($deliveries && $sinkSettled && $coroutinesSettled) {
            $streak[] = $last;

            if (count($streak) === QUIESCENCE_POLLS) {
                return ['first' => $streak[0], 'last' => $last, 'waited' => $last['at'] - $started];
            }
        } else {
            $streak = [];
        }

        if (microtime(true) - $started > QUIESCENCE_DEADLINE) {
            throw new RuntimeException('No quiescence within the deadline. Last state: ' . json_encode($last));
        }

        usleep((int) (QUIESCENCE_INTERVAL * 1e6));
    }
}

/**
 * Run the load generator, split across processes for higher concurrency.
 */
function load(string $path, int $requests, int $concurrency, string $expect, ?int $rssPid, string $scratch): array
{
    $processes = min($concurrency, $requests, count(LOADGEN_CPUS));
    $startAt = microtime(true) + 0.3;
    $running = [];
    $summaries = [];
    $latencies = [];

    try {
        for ($index = 0; $index < $processes; ++$index) {
            $share = intdiv($requests, $processes) + ($index < $requests % $processes ? 1 : 0);
            $latencyFile = "{$scratch}/latencies-{$index}.bin";
            $command = [
                PHP_BINARY, ...PHP_FLAGS, __DIR__ . '/Fixtures/loadgen.php',
                '--port=' . APP_PORT, "--path={$path}", "--requests={$share}",
                '--concurrency=' . (intdiv($concurrency, $processes) + ($index < $concurrency % $processes ? 1 : 0)), "--expect={$expect}",
                "--start-at={$startAt}", "--latencies={$latencyFile}",
            ];

            if ($index === 0 && $rssPid !== null) {
                $command[] = "--rss-pid={$rssPid}";
            }

            $command = pin($command, LOADGEN_CPUS[$index]);
            $errorLog = "{$scratch}/loadgen-{$index}.log";
            $running[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errorLog, 'a']], $pipes), $pipes, $latencyFile, $errorLog];
        }

        foreach ($running as [$process, $pipes, $file, $errorLog]) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $summary = json_decode(trim($output), true);

            if ($status !== 0 || $summary === null) {
                throw new RuntimeException("Load generator failed ({$status}); see {$errorLog}.\n{$output}");
            }
            $summaries[] = $summary;
            array_push($latencies, ...array_values(unpack('P*', file_get_contents($file))));
            unlink($file);
        }
    } finally {
        foreach ($running as [$process, $pipes]) {
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    sort($latencies);
    $count = count($latencies);
    if ($count !== $requests) {
        throw new RuntimeException("Expected {$requests} completed requests, got {$count}.");
    }
    $percentile = static fn (float $rank): float => $latencies[min($count - 1, (int) ceil($rank * $count) - 1)] / 1e6;
    $started = min(array_column($summaries, 'started'));
    $finished = max(array_column($summaries, 'finished'));

    return [
        'requests' => $count,
        'failures' => array_sum(array_column($summaries, 'failures')),
        'first_failure' => array_values(array_filter(array_column($summaries, 'first_failure')))[0] ?? null,
        'window_s' => $finished - $started,
        'finished' => $finished,
        'throughput_rps' => $count / ($finished - $started),
        'latency_ms' => [
            'p50' => $percentile(0.50),
            'p95' => $percentile(0.95),
            'p99' => $percentile(0.99),
            'max' => $latencies[$count - 1] / 1e6,
            'mean' => array_sum($latencies) / $count / 1e6,
        ],
        'loadgen_cpu_s' => array_column($summaries, 'cpu'),
        'peak_rss_kb' => $summaries[0]['peak_rss_kb'],
    ];
}

/**
 * Subtract sink counts.
 */
function sinkDelta(array $after, array $before): array
{
    $delta = [];

    foreach (['envelopes', 'bytes', 'raw_bytes', 'unparsed'] as $key) {
        $delta[$key] = $after[$key] - $before[$key];
    }

    foreach (['items', 'transactions', 'errors'] as $key) {
        $delta[$key] = [];

        foreach ($after[$key] as $name => $count) {
            $difference = $count - ($before[$key][$name] ?? 0);

            if ($difference !== 0) {
                $delta[$key][$name] = $difference;
            }
        }
    }

    return $delta;
}

/**
 * Check a window's delivered items against the configuration.
 */
function checkItems(string $config, array $delta, int $requests, string $transaction, string $label): array
{
    $problems = [];

    if (in_array($config, ['C0', 'C1', 'C2', 'C3'], true)) {
        if ($delta['envelopes'] !== 0) {
            $problems[] = "{$label}: expected no envelopes, got " . json_encode($delta);
        }

        return $problems;
    }

    $expected = ['transaction' => $requests];

    if ($delta['items'] !== $expected || $delta['transactions'] !== [$transaction => $requests] || $delta['unparsed'] !== 0) {
        $problems[] = "{$label}: expected {$requests} {$transaction} transactions only, got " . json_encode($delta);
    }

    return $problems;
}

/**
 * Pick the worker state recorded at a boundary.
 */
function workerState(array $worker): array
{
    return [
        'memory' => $worker['memory'],
        'memory_real' => $worker['memory_real'],
        'peak' => $worker['peak'],
        'peak_real' => $worker['peak_real'],
        'rss_kb' => $worker['rss_kb'],
        'coroutines' => $worker['coroutines'],
        'gc_runs' => $worker['gc']['runs'],
        'sentry' => $worker['sentry'],
    ];
}

/**
 * Check that the worker runs with OPcache on and JIT off.
 */
function runtimeProblems(array $worker): array
{
    return $worker['opcache'] === ['enabled' => true, 'jit' => false]
        ? []
        : ['runtime: expected OPcache on and JIT off, got ' . json_encode($worker['opcache'])];
}

/**
 * Run one workload between quiescent boundaries.
 */
function workload(string $config, int $master, int $workerPid, array $workload, string $scratch): array
{
    $warmup = load($workload['path'], $workload['warmup'], $workload['concurrency'], $workload['expect'], null, $scratch);
    $warmed = quiescence();
    stats(resetPeak: true);
    $sinkBefore = sink();
    $cpuBefore = serverCpu($master);

    $measured = load($workload['path'], $workload['requests'], $workload['concurrency'], $workload['expect'], $workerPid, $scratch);
    $cpuWindow = serverCpu($master);
    $drained = quiescence($warmed['last']['worker']['coroutines']);
    $cpuTotal = serverCpu($master);
    $sinkAfter = $drained['last']['sink'];

    $warmupDelta = sinkDelta($warmed['last']['sink'], $workload['sink_start']);
    $measuredDelta = sinkDelta($sinkAfter, $sinkBefore);
    $problems = array_merge(
        $warmup['failures'] > 0 ? ['warmup: ' . json_encode($warmup['first_failure'])] : [],
        $measured['failures'] > 0 ? ['measured: ' . json_encode($measured['first_failure'])] : [],
        checkItems($config, $warmupDelta, $workload['warmup'], $workload['transaction'], 'warmup'),
        checkItems($config, $measuredDelta, $workload['requests'], $workload['transaction'], 'measured'),
    );

    return [
        'workload' => $workload,
        'measured' => $measured,
        'server_cpu_window_s' => $cpuWindow - $cpuBefore,
        'server_cpu_total_s' => $cpuTotal - $cpuBefore,
        'cpu_per_request_window_us' => ($cpuWindow - $cpuBefore) / $measured['requests'] * 1e6,
        'cpu_per_request_total_us' => ($cpuTotal - $cpuBefore) / $measured['requests'] * 1e6,
        'drain_ms' => ($drained['first']['at'] - $measured['finished']) * 1000,
        'warmed' => workerState($warmed['last']['worker']),
        'after' => workerState($drained['last']['worker']),
        'sink_warmup' => $warmupDelta,
        'sink_measured' => $measuredDelta,
        'problems' => $problems,
    ];
}

/**
 * Scale workloads down for smoke runs.
 */
function scaled(array $workload, bool $smoke, array $smokeValues): array
{
    return $smoke ? array_replace($workload, $smokeValues) : $workload;
}

/**
 * Record the revisions and runtime of a run.
 */
function metadata(string $app): array
{
    $setup = json_decode(file_get_contents(ROOT . "/app-{$app}/benchmark.json"), true, flags: JSON_THROW_ON_ERROR);
    $components = $setup['components'];

    return [
        'app' => $app,
        'components' => trim(shell_exec('git -C ' . escapeshellarg($components) . ' rev-parse --short HEAD')),
        // shell_exec() returns null for empty output.
        'components_dirty' => trim((string) shell_exec('git -C ' . escapeshellarg($components) . ' status --porcelain --untracked-files=no')) !== '',
        'php' => PHP_VERSION,
        'swoole' => phpversion('swoole'),
        'platform' => php_uname(),
        'clock_ticks' => CLOCK_TICKS,
        'affinity' => ['server' => SERVER_CPUS, 'sink' => SINK_CPUS, 'loadgen' => LOADGEN_CPUS],
        'dependencies' => json_decode(command(['composer', 'show', '--format=json'], ROOT . "/app-{$app}"), true, flags: JSON_THROW_ON_ERROR)['installed'],
        'setup' => $setup,
        'started_at' => date(DATE_ATOM),
    ];
}

/**
 * Launch the server for one configuration and run the three HTTP workloads.
 */
function launch(string $app, string $config, bool $smoke, string $out, string $tag): array
{
    sink('/delay?ms=0');
    prepare($app, $config);
    $scratch = "{$out}/scratch";
    @mkdir($scratch, 0777, true);
    $result = metadata($app) + ['config' => $config, 'smoke' => $smoke, 'workloads' => []];
    $sinkStart = sink();
    [$process, $master] = startServer($app, $config, "{$out}/{$tag}.server.log");

    try {
        $idle = quiescence();
        $result['idle'] = workerState($idle['last']['worker']);
        $result['problems'] = runtimeProblems($idle['last']['worker']);
        $workerPid = $idle['last']['worker']['pid'];

        foreach (WORKLOADS as $name => $workload) {
            $workload = scaled($workload, $smoke, SMOKE_WORKLOAD) + ['sink_start' => sink()];
            $result['workloads'][$name] = workload($config, $master, $workerPid, $workload, $scratch);
            unset($result['workloads'][$name]['workload']['sink_start']);
        }

        if ($config === 'C0' || $config === 'C1') {
            $total = sinkDelta(sink(), $sinkStart);

            if ($total['envelopes'] !== 0) {
                $result['problems'][] = 'launch: expected no envelopes, got ' . json_encode($total);
            }
        }
    } finally {
        stopServer($process, $master);
    }

    $result['problems'] = array_merge($result['problems'] ?? [], ...array_column($result['workloads'], 'problems'));
    file_put_contents("{$out}/{$tag}.json", json_encode($result, JSON_PRETTY_PRINT));

    return $result;
}

/**
 * Saturate the transport pool with a delayed sink, in two equal waves on the same worker.
 */
function saturation(string $app, bool $smoke, string $out, string $tag): array
{
    $settings = scaled(SATURATION, $smoke, SMOKE_SATURATION);
    sink('/delay?ms=0');
    prepare($app, 'C4');
    $scratch = "{$out}/scratch";
    @mkdir($scratch, 0777, true);
    $result = metadata($app) + ['config' => 'C4', 'smoke' => $smoke, 'saturation' => $settings, 'waves' => []];
    [$process, $master] = startServer($app, 'C4', "{$out}/{$tag}.server.log");

    try {
        $idle = quiescence();
        $workerPid = $idle['last']['worker']['pid'];
        $warmup = load('/work', $settings['warmup'], $settings['concurrency'], $settings['expect'], null, $scratch);
        $warmed = quiescence();
        $result['warmed'] = workerState($warmed['last']['worker']);
        // Recorded rather than checked: the undelayed warmup at c=64 may still find the pool busy.
        $result['warmup_sink'] = sinkDelta($warmed['last']['sink'], $idle['last']['sink']);
        $result['problems'] = runtimeProblems($idle['last']['worker']);

        if ($warmup['failures'] > 0) {
            $result['problems'][] = 'warmup: ' . json_encode($warmup['first_failure']);
        }
        sink('/delay?ms=' . $settings['delay_ms']);

        for ($wave = 1; $wave <= 2; ++$wave) {
            stats(resetPeak: true);
            $sinkBefore = sink();
            $cpuBefore = serverCpu($master);
            $measured = load($settings['path'], $settings['wave'], $settings['concurrency'], $settings['expect'], $workerPid, $scratch);
            $cpuWindow = serverCpu($master);
            $drained = quiescence($warmed['last']['worker']['coroutines']);
            $delta = sinkDelta($drained['last']['sink'], $sinkBefore);
            // The sink proves what arrived; it cannot tell a skipped send from a failed delivery.
            $received = [
                'errors' => $delta['items']['event'] ?? 0,
                'transactions' => $delta['items']['transaction'] ?? 0,
            ];

            if ($measured['failures'] > 0) {
                $result['problems'][] = "wave {$wave}: " . json_encode($measured['first_failure']);
            }

            if (array_diff(array_keys($delta['items']), ['event', 'transaction']) !== []
                || array_diff(array_keys($delta['errors']), ['bench report']) !== []
                || array_diff(array_keys($delta['transactions']), ['/work']) !== []
                || $delta['unparsed'] !== 0
                || $received['errors'] > $measured['requests']
                || $received['transactions'] > $measured['requests']
            ) {
                $result['problems'][] = "wave {$wave}: unexpected items " . json_encode($delta);
            }

            $result['waves'][$wave] = [
                'measured' => $measured,
                'server_cpu_window_s' => $cpuWindow - $cpuBefore,
                'server_cpu_total_s' => serverCpu($master) - $cpuBefore,
                'drain_ms' => ($drained['first']['at'] - $measured['finished']) * 1000,
                'attempts' => $measured['requests'],
                'received' => $received,
                'not_received' => [
                    'errors' => $measured['requests'] - $received['errors'],
                    'transactions' => $measured['requests'] - $received['transactions'],
                ],
                'after' => workerState($drained['last']['worker']),
                'sink' => $delta,
            ];
        }
    } finally {
        stopServer($process, $master);
        sink('/delay?ms=0');
    }

    file_put_contents("{$out}/{$tag}.json", json_encode($result, JSON_PRETTY_PRINT));

    return $result;
}

/**
 * Print a one-line summary of a launch.
 */
function summarize(string $tag, array $result): void
{
    $parts = [];

    // Smoke runs check correctness only, so their timings are not shown.
    foreach ($result['workloads'] ?? [] as $name => $workload) {
        if ($result['smoke']) {
            $parts[] = sprintf('%s %d req items %s', $name, $workload['measured']['requests'], json_encode($workload['sink_measured']['items']));

            continue;
        }

        $parts[] = sprintf(
            '%s %d req %.0f rps p50 %.2f ms cpu %.0f us drain %.0f ms',
            $name,
            $workload['measured']['requests'],
            $workload['measured']['throughput_rps'],
            $workload['measured']['latency_ms']['p50'],
            $workload['cpu_per_request_total_us'],
            $workload['drain_ms'],
        );
    }

    foreach ($result['waves'] ?? [] as $wave => $data) {
        $parts[] = sprintf(
            'wave %d %d req received errors/transactions %d/%d not received %d/%d coroutines %d%s',
            $wave,
            $data['attempts'],
            $data['received']['errors'],
            $data['received']['transactions'],
            $data['not_received']['errors'],
            $data['not_received']['transactions'],
            $data['after']['coroutines'],
            $result['smoke'] ? '' : sprintf(' drain %.0f ms', $data['drain_ms']),
        );
    }

    $problems = $result['problems'] ?? [];
    echo $tag, ' ', $problems === [] ? 'OK' : 'PROBLEMS', ' | ', implode(' | ', $parts), PHP_EOL;

    foreach ($problems as $problem) {
        echo '  - ', $problem, PHP_EOL;
    }
}

// getopt() stops at the first positional argument, so read the flags directly.
$arguments = [];
$flags = [];

foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--')) {
        $arguments[] = $argument;

        continue;
    }

    [$name, $value] = explode('=', substr($argument, 2), 2) + [1 => true];

    if (! in_array($name, ['help', 'smoke', 'out', 'workspace', 'repetitions', 'idle-confirmed', 'app-port', 'stats-port', 'sink-port', 'server-cpus', 'sink-cpus', 'loadgen-cpus'], true)) {
        fwrite(STDERR, "Unknown option --{$name}\n");
        exit(1);
    }

    $flags[$name] = $value;
}

if (isset($flags['help']) || $arguments === []) {
    echo "Usage: php benchmark.php matrix|launch SIDE CONFIG|saturation SIDE --workspace=/tmp/sentry-benchmark [options]\n"
        . "SIDE is baseline or current; CONFIG is C0..C5. See README.md for setup.\n"
        . "--smoke | --idle-confirmed   Correctness checks or owner-authorized measurements\n"
        . "--repetitions=3 --out=/tmp/results\n"
        . "--app-port=18000 --stats-port=18001 --sink-port=18080\n"
        . "Optional Linux affinity: --server-cpus=1,2 --sink-cpus=3 --loadgen-cpus=4,5\n";
    exit(isset($flags['help']) ? 0 : 1);
}

if (! in_array($arguments[0], ['launch', 'saturation', 'matrix'], true)
    || ($arguments[0] !== 'matrix' && ! in_array($arguments[1] ?? null, ['baseline', 'current'], true))
    || ($arguments[0] === 'launch' && ! in_array($arguments[2] ?? null, ['C0', 'C1', 'C2', 'C3', 'C4', 'C5'], true))
) {
    throw new InvalidArgumentException('Unknown command, side or configuration. See --help.');
}

$workspace = realpath((string) ($flags['workspace'] ?? ''));
if (! isset($flags['workspace']) || $workspace === false || ! is_dir($workspace)) {
    throw new InvalidArgumentException('Supply --workspace pointing to the prepared scratch applications.');
}
define('ROOT', $workspace);

foreach (['APP_PORT' => 'app-port', 'STATS_PORT' => 'stats-port', 'SINK_PORT' => 'sink-port'] as $constant => $option) {
    $port = filter_var($flags[$option] ?? match ($option) {
        'app-port' => 18000, 'stats-port' => 18001, 'sink-port' => 18080,
    }, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        throw new InvalidArgumentException("Invalid --{$option}.");
    }
    define($constant, $port);
}
if (count(array_unique([APP_PORT, STATS_PORT, SINK_PORT])) !== 3) {
    throw new InvalidArgumentException('Application, stats and sink ports must differ.');
}
foreach (['server-cpus', 'sink-cpus', 'loadgen-cpus'] as $option) {
    if (isset($flags[$option]) && ! preg_match('/^\d+(?:,\d+)*$/', (string) $flags[$option])) {
        throw new InvalidArgumentException("--{$option} must be a comma-separated list of CPU numbers.");
    }
}
define('SERVER_CPUS', $flags['server-cpus'] ?? null);
define('SINK_CPUS', $flags['sink-cpus'] ?? null);
define('LOADGEN_CPUS', isset($flags['loadgen-cpus']) ? explode(',', $flags['loadgen-cpus']) : [null, null]);
$clockTicks = (int) command(['getconf', 'CLK_TCK'], ROOT);
if ($clockTicks < 1) {
    throw new RuntimeException('Could not determine Linux clock ticks.');
}
define('CLOCK_TICKS', $clockTicks);

// Full-size runs are benchmarks, which need the owner's confirmation that the machine is idle.
if (! isset($flags['smoke']) && ! isset($flags['idle-confirmed'])) {
    fwrite(STDERR, "Full-size runs need --idle-confirmed; use --smoke otherwise.\n");
    exit(1);
}

$smoke = isset($flags['smoke']);
$repetitions = filter_var($flags['repetitions'] ?? ($smoke ? 1 : 3), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($repetitions === false) {
    throw new InvalidArgumentException('--repetitions must be positive.');
}
$out = $flags['out'] ?? ROOT . '/results/' . date('Ymd-His') . ($smoke ? '-smoke' : '');
@mkdir($out, 0777, true);

$failed = false;
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, static function (int $signal): never {
        throw new RuntimeException("Benchmark interrupted by signal {$signal}.");
    });
}
[$sinkProcess, $sinkPid] = startSink("{$out}/sink.log");
try {
    switch ($arguments[0] ?? null) {
        case 'launch':
            $tag = "{$arguments[1]}-{$arguments[2]}";
            $result = launch($arguments[1], $arguments[2], $smoke, $out, $tag);
            summarize($tag, $result);
            $failed = $result['problems'] !== [];
            break;
        case 'saturation':
            $tag = "{$arguments[1]}-saturation";
            $result = saturation($arguments[1], $smoke, $out, $tag);
            summarize($tag, $result);
            $failed = $result['problems'] !== [];
            break;
        case 'matrix':
            for ($repetition = 1; $repetition <= $repetitions; ++$repetition) {
                foreach (['C0', 'C1', 'C2', 'C3', 'C4', 'C5'] as $index => $config) {
                    // Alternate within a repetition and between repetitions, so each configuration's first revision changes.
                    $apps = ($index + $repetition) % 2 === 1 ? ['baseline', 'current'] : ['current', 'baseline'];

                    foreach ($apps as $app) {
                        $tag = "{$app}-{$config}-r{$repetition}";
                        $result = launch($app, $config, $smoke, $out, $tag);
                        summarize($tag, $result);
                        $failed = $failed || $result['problems'] !== [];
                    }
                }
            }

            for ($repetition = 1; $repetition <= $repetitions; ++$repetition) {
                $apps = $repetition % 2 === 1 ? ['baseline', 'current'] : ['current', 'baseline'];

                foreach ($apps as $app) {
                    $tag = "{$app}-saturation-r{$repetition}";
                    $result = saturation($app, $smoke, $out, $tag);
                    summarize($tag, $result);
                    $failed = $failed || $result['problems'] !== [];
                }
            }

            break;
    }
} finally {
    stopServer($sinkProcess, $sinkPid);
}

echo "Results: {$out}", PHP_EOL;
exit($failed ? 1 : 0);
