<?php

declare(strict_types=1);

/*
 * Keep-alive HTTP load generator for one benchmark process.
 *
 *   php loadgen.php --port=N --path=/work --requests=N --concurrency=N --expect=TEXT
 *       [--start-at=EPOCH] [--latencies=FILE] [--rss-pid=PID]
 *
 * Each coroutine owns one keep-alive connection and keeps the cookies the server sets, like a browser.
 * Every response must be 200 and contain the expected text. Latencies are written as packed
 * nanosecond integers so several processes can be merged. The JSON summary goes to stdout.
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

$options = getopt('', ['port:', 'path:', 'requests:', 'concurrency:', 'expect:', 'start-at::', 'latencies::', 'rss-pid::']);

$port = (int) $options['port'];
$path = $options['path'];
$requests = (int) $options['requests'];
$concurrency = (int) $options['concurrency'];
$expect = $options['expect'];
$startAt = isset($options['start-at']) ? (float) $options['start-at'] : null;
$latencyFile = $options['latencies'] ?? null;
$rssPid = isset($options['rss-pid']) ? (int) $options['rss-pid'] : null;

/**
 * Read a process's resident set size in kilobytes.
 */
function rss(int $pid): ?int
{
    $status = @file_get_contents("/proc/{$pid}/status");

    return $status !== false && preg_match('/^VmRSS:\s+(\d+)/m', $status, $matches) ? (int) $matches[1] : null;
}

/**
 * Read this process's CPU time in seconds.
 */
function cpu(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
}

Coroutine\run(static function () use ($port, $path, $requests, $concurrency, $expect, $startAt, $latencyFile, $rssPid): void {
    $latencies = [];
    $next = 0;
    $failures = 0;
    $firstFailure = null;
    $peakRss = null;
    $running = true;

    if ($startAt !== null && ($wait = $startAt - microtime(true)) > 0) {
        Coroutine::sleep($wait);
    }

    if ($rssPid !== null) {
        Coroutine::create(static function () use ($rssPid, &$peakRss, &$running): void {
            while ($running) {
                $peakRss = max($peakRss ?? 0, rss($rssPid) ?? 0);
                Coroutine::sleep(0.05);
            }
        });
    }

    $cpuStart = cpu();
    $started = microtime(true);
    $workers = [];

    for ($worker = 0; $worker < $concurrency; ++$worker) {
        $workers[] = Coroutine::create(static function () use ($port, $path, $requests, $expect, &$next, &$latencies, &$failures, &$firstFailure): void {
            $client = new Client('127.0.0.1', $port);
            $client->set(['timeout' => 30, 'keep_alive' => true]);

            while ($next < $requests) {
                ++$next;
                $begin = hrtime(true);
                $client->get($path);
                $latencies[] = hrtime(true) - $begin;

                if ($client->statusCode !== 200 || ! str_contains((string) $client->body, $expect)) {
                    ++$failures;
                    $firstFailure ??= [
                        'status' => $client->statusCode,
                        'error' => $client->errMsg,
                        'body' => substr((string) $client->body, 0, 500),
                    ];
                }
            }

            $client->close();
        });
    }

    Coroutine::join($workers);

    $finished = microtime(true);
    $cpuUsed = cpu() - $cpuStart;
    $running = false;

    if ($latencyFile !== null) {
        file_put_contents($latencyFile, pack('P*', ...$latencies));
    }

    echo json_encode([
        'requests' => count($latencies),
        'failures' => $failures,
        'first_failure' => $firstFailure,
        'started' => $started,
        'finished' => $finished,
        'cpu' => $cpuUsed,
        'peak_rss_kb' => $peakRss,
    ]), PHP_EOL;
});
