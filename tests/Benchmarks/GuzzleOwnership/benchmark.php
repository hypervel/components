#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\Credentials\Credentials;
use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\HttpServiceProvider;
use Hypervel\Support\Aws\SerializedCredentialProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

$options = getopt('', ['root:', 'endpoint:', 'scenario:', 'operations:', 'concurrency:', 'samples:', 'storage:', 'outside', 'smoke', 'idle-confirmed', 'help']);
if (isset($options['help'])) {
    echo "Run measurements on an idle machine. Output is JSON.\n"
        . "--root=/checkout --endpoint=http://127.0.0.1:PORT --scenario=promise --smoke|--idle-confirmed\n"
        . "--operations=1000 --concurrency=8 --samples=5 --storage=/tmp/guzzle-benchmark-current\n"
        . "Scenarios: idle, promise, sync, direct-sync, async, parallel, direct-parallel, middleware, global-middleware, credentials, pusher, cancel, cancel-retention.\n"
        . "Add --outside for workloads that do not create child coroutines.\n";
    exit(0);
}

$root = realpath($options['root'] ?? getcwd());
$scenario = $options['scenario'] ?? 'promise';
$outside = isset($options['outside']);
$smoke = isset($options['smoke']);
$endpoint = $options['endpoint'] ?? '';
if (! in_array($scenario, ['idle', 'promise', 'sync', 'direct-sync', 'async', 'parallel', 'direct-parallel', 'middleware', 'global-middleware', 'credentials', 'pusher', 'cancel', 'cancel-retention'], true)) {
    throw new InvalidArgumentException('Unknown scenario. See --help.');
}
if (! $smoke && ! isset($options['idle-confirmed'])) {
    throw new InvalidArgumentException('Confirm the machine is idle with --idle-confirmed, or use --smoke.');
}
if ($outside && in_array($scenario, ['parallel', 'direct-parallel', 'credentials'], true)) {
    throw new InvalidArgumentException('This workload creates child coroutines and cannot run outside a coroutine.');
}
if (! in_array($scenario, ['idle', 'promise'], true) && ! str_starts_with($endpoint, 'http://127.0.0.1:')) {
    throw new InvalidArgumentException('Supply the separate loopback origin URL.');
}
$settings = [];
foreach (['operations' => 1000, 'concurrency' => 8, 'samples' => 5] as $key => $default) {
    $value = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        throw new InvalidArgumentException("--{$key} must be a positive integer.");
    }
    $settings[$key] = $value;
}
if ($smoke) {
    $settings['operations'] = min(32, $settings['operations']);
    $settings['samples'] = 1;
}

/**
 * Read client resources, excluding the separately running origin.
 */
function resources(): array
{
    preg_match('/^VmRSS:\s+(\d+) kB$/m', file_get_contents('/proc/self/status'), $resident);

    return [
        'heap_bytes' => memory_get_usage(),
        'allocated_bytes' => memory_get_usage(true),
        'peak_bytes' => memory_get_peak_usage(true),
        'rss_bytes' => (int) $resident[1] * 1024,
        'open_fds' => count(scandir('/proc/self/fd')) - 2,
        'gc' => gc_status(),
    ];
}

/**
 * Return CPU consumed by this process in seconds.
 */
function cpuSeconds(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']
        + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
}

/**
 * Return measured operation latency percentiles in milliseconds.
 */
function percentiles(array $latencies): array
{
    sort($latencies, SORT_NUMERIC);

    return [
        'p50_ms' => $latencies[(int) ceil(count($latencies) * 0.5) - 1],
        'p95_ms' => $latencies[(int) ceil(count($latencies) * 0.95) - 1],
    ];
}

$startupStarted = hrtime(true);
$startupCpu = cpuSeconds();
$initialResources = resources();
$loader = require $root . '/vendor/autoload.php';
$app = new Application($root);
$storage = $options['storage'] ?? sys_get_temp_dir() . '/hypervel-guzzle-benchmark-' . getmypid();
$app->useStoragePath($storage);
$app->useBootstrapPath($storage . '/bootstrap');
$cachedProxies = is_dir($app->bootstrapPath('cache/aop'));
(new HttpServiceProvider($app))->register();

(new GenerateProxies)->bootstrap($app);
$startup = [
    'proxy_cache_preexisting' => $cachedProxies,
    'wall_ms' => (hrtime(true) - $startupStarted) / 1e6,
    'cpu_ms' => (cpuSeconds() - $startupCpu) * 1000,
    'before' => $initialResources,
    'after' => resources(),
];

$git = new Process(['git', 'rev-parse', 'HEAD'], $root);
$git->mustRun();
$cpuInfo = file_get_contents('/proc/cpuinfo');
preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $cpuModel);
$metadata = [
    'commit' => trim($git->getOutput()),
    'php' => PHP_VERSION,
    'swoole' => phpversion('swoole'),
    'curl' => curl_version(),
    'guzzle' => InstalledVersions::getPrettyVersion(InstalledVersions::isInstalled('hypervel/guzzle') ? 'hypervel/guzzle' : 'guzzlehttp/guzzle'),
    'promises' => InstalledVersions::getPrettyVersion(InstalledVersions::isInstalled('hypervel/guzzle-promises') ? 'hypervel/guzzle-promises' : 'guzzlehttp/promises'),
    'psr7' => InstalledVersions::getPrettyVersion('guzzlehttp/psr7'),
    'system' => php_uname(),
    'cpu' => [
        'model' => $cpuModel[1] ?? null,
        'logical_processors' => preg_match_all('/^processor\s*:/m', $cpuInfo),
    ],
    'opcache' => ini_get('opcache.enable_cli'),
    'opcache_file_update_protection' => ini_get('opcache.file_update_protection'),
    'jit' => ini_get('opcache.jit'),
    'autoload_classmap_entries' => count($loader->getClassMap()),
    'autoload_classmap_authoritative' => $loader->isClassMapAuthoritative(),
    'gc_enabled' => gc_enabled(),
    'command' => $argv,
    'scenario' => $scenario,
    'outside_coroutines' => $outside,
    'smoke' => $smoke,
    'settings' => $settings,
    'storage' => $storage,
];
unset($cpuInfo, $cpuModel);
$reports = [];
$failure = null;
$workload = static function () use ($app, $scenario, $endpoint, $settings, &$reports, &$failure): void {
    try {
        if ($scenario === 'global-middleware') {
            if (! method_exists(Client::class, 'pushGlobalMiddleware')) {
                throw new LogicException('Global middleware requires the Hypervel Guzzle fork.');
            }
            $middleware = static fn (callable $handler): Closure => static fn (RequestInterface $request, array $options): PromiseInterface => $handler($request, $options);
            Client::pushGlobalMiddleware($middleware, 'benchmark.first');
            Client::pushGlobalMiddleware($middleware, 'benchmark.second');
        }

        $client = in_array($scenario, ['async', 'credentials', 'cancel', 'direct-sync', 'direct-parallel', 'global-middleware'], true)
            ? new Client(['timeout' => 5, 'connect_timeout' => 2, 'proxy' => ''])
            : null;
        $multiHandler = $scenario === 'cancel-retention' ? new CurlMultiHandler : null;
        $ports = [];
        $onStats = static function (TransferStats $stats) use (&$ports): void {
            $ports[$stats->getHandlerStat('local_port')] = true;
        };
        $factory = in_array($scenario, ['sync', 'parallel'], true) ? $app->make(Factory::class) : null;
        $factory?->registerConnection('benchmark', ['timeout' => 5, 'proxy' => '']);
        $eventCount = 0;
        $middlewareClient = null;
        if ($scenario === 'middleware') {
            $events = new Dispatcher;
            $events->listen('benchmark.response', static function () use (&$eventCount): void { ++$eventCount; });
            $stack = HandlerStack::create();
            $stack->push(static fn (callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $events): PromiseInterface {
                return $handler($request, $options)->then(static function (ResponseInterface $response) use ($events): ResponseInterface {
                    $events->dispatch('benchmark.response');

                    return $response;
                });
            });
            $middlewareClient = new Client(['handler' => $stack, 'timeout' => 5, 'proxy' => '']);
        }
        $provider = static function () use ($client, $endpoint): PromiseInterface {
            $promise = new Promise(static function () use (&$promise, $client, $endpoint): void {
                $client->get($endpoint);
                $promise->resolve(new Credentials('benchmark', 'secret'));
            });

            return $promise;
        };
        $credentials = $scenario === 'credentials' && class_exists(SerializedCredentialProvider::class)
            ? new SerializedCredentialProvider($provider)
            : $provider;
        $pusher = null;
        if ($scenario === 'pusher') {
            $pusher = (new BroadcastManager($app))->pusher([
                'key' => 'key', 'secret' => 'secret', 'app_id' => 'app',
                'options' => ['host' => '127.0.0.1', 'port' => parse_url($endpoint, PHP_URL_PORT), 'scheme' => 'http', 'useTLS' => false],
                'client_options' => ['timeout' => 5, 'proxy' => ''],
            ]);
        }

        $operation = static function () use ($scenario, $client, $factory, $middlewareClient, $credentials, $endpoint, $multiHandler, $onStats): mixed {
            if ($scenario === 'idle') {
                return 3;
            }
            if ($scenario === 'promise') {
                $promise = new Promise;
                $result = $promise->then(static fn (int $value): int => $value + 1)
                    ->then(static fn (int $value): int => $value + 1);
                $promise->resolve(1);

                return $result->wait();
            }
            if ($scenario === 'credentials') {
                return $credentials()->wait()->getAccessKeyId();
            }
            if ($scenario === 'cancel') {
                $promise = $client->getAsync($endpoint);
                $promise->cancel();
                Utils::queue()->run();

                return $promise->getState();
            }
            if ($scenario === 'cancel-retention') {
                $promise = $multiHandler(new Request('GET', $endpoint), ['timeout' => 5, 'proxy' => '']);
                $promise->cancel();

                return $promise->getState();
            }
            if (in_array($scenario, ['direct-sync', 'global-middleware'], true)) {
                return (string) $client->get($endpoint)->getBody();
            }
            if ($scenario === 'direct-parallel') {
                return (string) $client->get($endpoint, ['on_stats' => $onStats])->getBody();
            }
            if ($scenario === 'middleware') {
                return (string) $middlewareClient->get($endpoint)->getBody();
            }

            return $factory->connection('benchmark')->get($endpoint)->throw()->body();
        };

        for ($sample = -1; $sample < $settings['samples']; ++$sample) {
            $count = $sample < 0 ? min(32, $settings['operations']) : $settings['operations'];
            $expected = match ($scenario) {
                'idle', 'promise' => 3,
                'credentials' => 'benchmark',
                'cancel', 'cancel-retention' => PromiseInterface::REJECTED,
                'pusher' => 'accepted',
                default => '{"result":"accepted"}',
            };
            $latencies = [];
            $values = [];
            $eventCount = 0;
            $ports = [];
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = resources();
            $cpu = cpuSeconds();
            $started = hrtime(true);
            for ($offset = 0; $offset < $count; $offset += $settings['concurrency']) {
                $size = min($settings['concurrency'], $count - $offset);
                if (in_array($scenario, ['async', 'pusher'], true)) {
                    $promises = [];
                    for ($index = 0; $index < $size; ++$index) {
                        $begin = hrtime(true);
                        $promise = $scenario === 'pusher'
                            ? $pusher->triggerAsync('channel', 'event', [])
                            : $client->getAsync($endpoint);
                        $promises[] = $promise->then(static function (mixed $response) use ($begin, $scenario, &$latencies): string {
                            $latencies[] = (hrtime(true) - $begin) / 1e6;

                            return $scenario === 'pusher' ? $response->result : (string) $response->getBody();
                        });
                    }
                    array_push($values, ...Utils::all($promises)->wait());
                    unset($promise, $promises);
                } else {
                    $measured = static function () use ($operation, &$latencies): mixed {
                        $begin = hrtime(true);
                        $value = $operation();
                        $latencies[] = (hrtime(true) - $begin) / 1e6;

                        return $value;
                    };
                    if (in_array($scenario, ['parallel', 'direct-parallel', 'credentials'], true)) {
                        array_push($values, ...parallel(array_fill(0, $size, $measured)));
                    } else {
                        for ($index = 0; $index < $size; ++$index) {
                            $values[] = $measured();
                        }
                    }
                }
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            $cpuElapsed = cpuSeconds() - $cpu;
            $after = resources();
            $afterDrain = null;
            if ($scenario === 'cancel-retention') {
                Utils::queue()->run();
                $afterDrain = resources();
            }
            if (count($values) !== $count || array_filter($values, static fn (mixed $value): bool => $value !== $expected) !== []) {
                throw new RuntimeException('The workload did not produce the expected completed results.');
            }
            if ($scenario === 'middleware' && $eventCount !== $count) {
                throw new RuntimeException('A middleware response event was lost.');
            }
            $latency = percentiles($latencies);
            unset($measured, $values, $latencies);
            gc_collect_cycles();
            $reports[] = [
                'warmup' => $sample < 0, 'operations' => $count,
                'wall_ms' => $elapsed * 1000, 'cpu_ms' => $cpuElapsed * 1000,
                'operations_per_second' => $count / $elapsed,
                'latency' => $latency, 'before' => $before, 'after' => $after, 'after_gc' => resources(),
                'after_drain' => $afterDrain,
                'connections' => $scenario === 'direct-parallel' ? count($ports) : null,
            ];
        }
        $factory?->forgetConnectionHandlers();
    } catch (Throwable $exception) {
        $failure = $exception;
    } finally {
        if ($scenario === 'global-middleware' && method_exists(Client::class, 'removeGlobalMiddleware')) {
            Client::removeGlobalMiddleware('benchmark.first');
            Client::removeGlobalMiddleware('benchmark.second');
        }
    }
};
$outside ? $workload() : run($workload);
unset($workload);
if ($failure !== null) {
    throw $failure;
}
gc_collect_cycles();
echo json_encode(['metadata' => $metadata, 'startup' => $startup, 'samples' => $reports, 'after_exit' => resources()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
