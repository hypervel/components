<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use LogicException;
use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Swoole\Coroutine;
use Swoole\Server;

class RuntimeInstrumentation extends AbstractInstrumentation
{
    protected const array MEMORY_METRICS = [
        'php.memory.usage',
        'php.memory.peak_usage',
        'php.memory.limit',
    ];

    protected const array GARBAGE_COLLECTION_METRICS = [
        'php.gc.runs',
        'php.gc.collected',
        'php.gc.threshold',
        'php.gc.roots',
        'php.gc.collector_time',
        'php.gc.destructor_time',
        'php.gc.free_time',
    ];

    protected const array CPU_METRICS = [
        'process.cpu.time',
        'process.context_switches',
    ];

    protected const array OPCACHE_METRICS = [
        'php.opcache.memory_used',
        'php.opcache.memory_free',
        'php.opcache.memory_wasted',
        'php.opcache.hit_rate',
        'php.opcache.hits',
        'php.opcache.misses',
        'php.opcache.cached_scripts',
        'php.opcache.interned_strings.memory_used',
        'php.opcache.interned_strings.memory_free',
        'php.opcache.interned_strings.count',
    ];

    protected const array SERVER_METRICS = [
        'hypervel.server.connections',
        'hypervel.server.requests',
        'hypervel.server.tasks.active',
        'hypervel.server.task_queue.size',
    ];

    protected const array WORKER_METRICS = [
        'hypervel.worker.requests',
    ];

    protected const array COROUTINE_METRICS = [
        'hypervel.worker.coroutines',
    ];

    /**
     * Create runtime instrumentation.
     */
    public function __construct(
        protected Container $container,
        protected MeterProviderInterface $meterProvider,
        protected ProcessIdentity $processIdentity,
    ) {
    }

    /**
     * Register runtime instruments and collection callbacks.
     */
    protected function registerInstrumentation(): void
    {
        $memoryMetrics = $this->enabledMetrics(self::MEMORY_METRICS);
        $garbageCollectionMetrics = $this->enabledMetrics(self::GARBAGE_COLLECTION_METRICS);
        $cpuMetrics = function_exists('getrusage')
            ? $this->enabledMetrics(self::CPU_METRICS)
            : [];
        $opcacheMetrics = $this->processIdentity->type === ProcessIdentity::EVENT
            && $this->processIdentity->workerId === 0
                ? $this->enabledMetrics(self::OPCACHE_METRICS)
                : [];

        if ($opcacheMetrics !== []
            && (! function_exists('opcache_get_status') || opcache_get_status(false) === false)
        ) {
            $opcacheMetrics = [];
        }
        $serverMetrics = $this->processIdentity->type === ProcessIdentity::EVENT
            && $this->processIdentity->workerId === 0
                ? $this->enabledMetrics(self::SERVER_METRICS)
                : [];
        $workerMetrics = in_array(
            $this->processIdentity->type,
            [ProcessIdentity::EVENT, ProcessIdentity::TASK],
            true,
        ) ? $this->enabledMetrics(self::WORKER_METRICS) : [];
        $coroutineMetrics = $this->enabledMetrics(self::COROUTINE_METRICS);

        if ($memoryMetrics === []
            && $garbageCollectionMetrics === []
            && $cpuMetrics === []
            && $opcacheMetrics === []
            && $serverMetrics === []
            && $workerMetrics === []
            && $coroutineMetrics === []
        ) {
            return;
        }

        $meter = $this->meterProvider->getMeter('hypervel.runtime');

        $this->registerBatch($meter, $memoryMetrics, $this->observeMemory(...));
        $this->registerBatch($meter, $garbageCollectionMetrics, $this->observeGarbageCollection(...));
        $this->registerBatch($meter, $cpuMetrics, $this->observeCpu(...));
        $this->registerBatch($meter, $opcacheMetrics, $this->observeOpcache(...));

        if ($serverMetrics !== [] || $workerMetrics !== []) {
            $server = $this->container->make(Server::class);
            $this->registerBatch(
                $meter,
                [...$serverMetrics, ...$workerMetrics],
                function (array $observers) use ($server): void {
                    $this->observeServer($server, $observers);
                },
            );
        }

        $this->registerBatch($meter, $coroutineMetrics, $this->observeCoroutines(...));
    }

    /**
     * Return enabled names from one snapshot group.
     *
     * @param list<string> $names
     * @return list<string>
     */
    protected function enabledMetrics(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => $this->metricEnabled($name),
        ));
    }

    /**
     * Register one batched snapshot callback.
     *
     * @param list<string> $names
     * @param Closure(array<string, ObserverInterface>): void $callback
     */
    protected function registerBatch(MeterInterface $meter, array $names, Closure $callback): void
    {
        if ($names === []) {
            return;
        }

        $instruments = array_map(
            fn (string $name): AsynchronousInstrument => $this->createInstrument($meter, $name),
            $names,
        );

        $meter->batchObserve(
            function (ObserverInterface ...$observers) use ($names, $callback): void {
                $callback($this->indexObservers($names, $observers));
            },
            $instruments[0],
            ...array_slice($instruments, 1),
        );
    }

    /**
     * Create one observable runtime instrument.
     */
    protected function createInstrument(MeterInterface $meter, string $name): AsynchronousInstrument
    {
        return match ($name) {
            'php.memory.usage' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'Current memory usage.',
            ),
            'php.memory.peak_usage' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'Peak memory usage since process start.',
            ),
            'php.memory.limit' => $meter->createObservableGauge(
                $name,
                'By',
                'Memory limit configured in php.ini.',
            ),
            'php.gc.runs' => $meter->createObservableCounter(
                $name,
                '{run}',
                'Total number of garbage collection cycles.',
            ),
            'php.gc.collected' => $meter->createObservableCounter(
                $name,
                '{object}',
                'Total number of objects collected by the garbage collector.',
            ),
            'php.gc.threshold' => $meter->createObservableGauge(
                $name,
                '{object}',
                'Number of roots needed to trigger a garbage collection cycle.',
            ),
            'php.gc.roots' => $meter->createObservableGauge(
                $name,
                '{object}',
                'Current number of objects in the root buffer.',
            ),
            'php.gc.collector_time' => $meter->createObservableCounter(
                $name,
                's',
                'Cumulative time spent in the garbage collector.',
            ),
            'php.gc.destructor_time' => $meter->createObservableCounter(
                $name,
                's',
                'Cumulative time spent running destructors during garbage collection.',
            ),
            'php.gc.free_time' => $meter->createObservableCounter(
                $name,
                's',
                'Cumulative time spent freeing memory during garbage collection.',
            ),
            'process.cpu.time' => $meter->createObservableCounter(
                $name,
                's',
                'CPU time consumed by the process.',
            ),
            'process.context_switches' => $meter->createObservableCounter(
                $name,
                '{context_switch}',
                'Number of times the process has been context switched.',
            ),
            'php.opcache.memory_used' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'OPcache memory used by cached scripts.',
            ),
            'php.opcache.memory_free' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'Free OPcache memory.',
            ),
            'php.opcache.memory_wasted' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'Wasted OPcache memory.',
            ),
            'php.opcache.hit_rate' => $meter->createObservableGauge(
                $name,
                '%',
                'OPcache hit rate.',
            ),
            'php.opcache.hits' => $meter->createObservableCounter(
                $name,
                '{hit}',
                'Total OPcache hits.',
            ),
            'php.opcache.misses' => $meter->createObservableCounter(
                $name,
                '{miss}',
                'Total OPcache misses.',
            ),
            'php.opcache.cached_scripts' => $meter->createObservableGauge(
                $name,
                '{script}',
                'Number of scripts currently cached in OPcache.',
            ),
            'php.opcache.interned_strings.memory_used' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'Memory used by OPcache interned strings.',
            ),
            'php.opcache.interned_strings.memory_free' => $meter->createObservableUpDownCounter(
                $name,
                'By',
                'Free memory in the OPcache interned strings buffer.',
            ),
            'php.opcache.interned_strings.count' => $meter->createObservableGauge(
                $name,
                '{string}',
                'Number of interned strings currently stored in OPcache.',
            ),
            'hypervel.server.connections' => $meter->createObservableUpDownCounter(
                $name,
                '{connection}',
                'Number of active server connections.',
            ),
            'hypervel.server.requests' => $meter->createObservableCounter(
                $name,
                '{request}',
                'Total number of server requests.',
            ),
            'hypervel.server.tasks.active' => $meter->createObservableUpDownCounter(
                $name,
                '{task}',
                'Number of active server tasks.',
            ),
            'hypervel.server.task_queue.size' => $meter->createObservableUpDownCounter(
                $name,
                '{task}',
                'Number of tasks waiting in the server task queue.',
            ),
            'hypervel.worker.requests' => $meter->createObservableCounter(
                $name,
                '{request}',
                'Total number of requests handled by this worker.',
            ),
            'hypervel.worker.coroutines' => $meter->createObservableUpDownCounter(
                $name,
                '{coroutine}',
                'Number of coroutines in this worker.',
            ),
            default => throw new LogicException("Runtime metric [{$name}] is not supported."),
        };
    }

    /**
     * Observe process memory metrics.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeMemory(array $observers): void
    {
        if (isset($observers['php.memory.usage'])) {
            $emalloc = memory_get_usage(false);
            $real = memory_get_usage(true);
            $observers['php.memory.usage']->observe($emalloc, ['memory.type' => 'emalloc']);
            $observers['php.memory.usage']->observe($real - $emalloc, ['memory.type' => 'overhead']);
        }

        if (isset($observers['php.memory.peak_usage'])) {
            $emalloc = memory_get_peak_usage(false);
            $real = memory_get_peak_usage(true);
            $observers['php.memory.peak_usage']->observe($emalloc, ['memory.type' => 'emalloc']);
            $observers['php.memory.peak_usage']->observe($real - $emalloc, ['memory.type' => 'overhead']);
        }

        if (isset($observers['php.memory.limit'])) {
            $limit = ini_parse_quantity(ini_get('memory_limit') ?: '-1');

            if ($limit >= 0) {
                $observers['php.memory.limit']->observe($limit);
            }
        }
    }

    /**
     * Observe garbage collection metrics.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeGarbageCollection(array $observers): void
    {
        $status = gc_status();
        $statusKeys = [
            'php.gc.runs' => 'runs',
            'php.gc.collected' => 'collected',
            'php.gc.threshold' => 'threshold',
            'php.gc.roots' => 'roots',
            'php.gc.collector_time' => 'collector_time',
            'php.gc.destructor_time' => 'destructor_time',
            'php.gc.free_time' => 'free_time',
        ];

        // PHP 8.4 guarantees every key in this snapshot; optional runtime snapshots below do not.
        foreach ($observers as $name => $observer) {
            $key = $statusKeys[$name];
            $observer->observe($status[$key]);
        }
    }

    /**
     * Observe process CPU metrics.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeCpu(array $observers): void
    {
        $usage = getrusage();

        if ($usage === false) {
            return;
        }

        if (isset(
            $observers['process.cpu.time'],
            $usage['ru_utime.tv_sec'],
            $usage['ru_utime.tv_usec'],
            $usage['ru_stime.tv_sec'],
            $usage['ru_stime.tv_usec'],
        )) {
            $observers['process.cpu.time']->observe(
                (float) $usage['ru_utime.tv_sec'] + (float) $usage['ru_utime.tv_usec'] / 1_000_000,
                ['cpu.mode' => 'user'],
            );
            $observers['process.cpu.time']->observe(
                (float) $usage['ru_stime.tv_sec'] + (float) $usage['ru_stime.tv_usec'] / 1_000_000,
                ['cpu.mode' => 'system'],
            );
        }

        if (isset($observers['process.context_switches'])) {
            if (isset($usage['ru_nvcsw'])) {
                $observers['process.context_switches']->observe(
                    $usage['ru_nvcsw'],
                    ['process.context_switch.type' => 'voluntary'],
                );
            }

            if (isset($usage['ru_nivcsw'])) {
                $observers['process.context_switches']->observe(
                    $usage['ru_nivcsw'],
                    ['process.context_switch.type' => 'involuntary'],
                );
            }
        }
    }

    /**
     * Observe shared OPcache metrics.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeOpcache(array $observers): void
    {
        /** @var array $status OPcache was active at registration and cannot be disabled at runtime. */
        $status = opcache_get_status(false);

        $statusPaths = [
            'php.opcache.memory_used' => ['memory_usage', 'used_memory'],
            'php.opcache.memory_free' => ['memory_usage', 'free_memory'],
            'php.opcache.memory_wasted' => ['memory_usage', 'wasted_memory'],
            'php.opcache.hit_rate' => ['opcache_statistics', 'opcache_hit_rate'],
            'php.opcache.hits' => ['opcache_statistics', 'hits'],
            'php.opcache.misses' => ['opcache_statistics', 'misses'],
            'php.opcache.cached_scripts' => ['opcache_statistics', 'num_cached_scripts'],
            'php.opcache.interned_strings.memory_used' => ['interned_strings_usage', 'used_memory'],
            'php.opcache.interned_strings.memory_free' => ['interned_strings_usage', 'free_memory'],
            'php.opcache.interned_strings.count' => ['interned_strings_usage', 'number_of_strings'],
        ];

        foreach ($observers as $name => $observer) {
            [$group, $key] = $statusPaths[$name];

            if (isset($status[$group][$key])) {
                $observer->observe($status[$group][$key]);
            }
        }
    }

    /**
     * Observe shared server and current-worker metrics.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeServer(Server $server, array $observers): void
    {
        $stats = $server->stats();
        $statKeys = [
            'hypervel.server.connections' => 'connection_num',
            'hypervel.server.requests' => 'request_count',
            'hypervel.server.tasks.active' => 'tasking_num',
            'hypervel.server.task_queue.size' => 'task_queue_num',
            'hypervel.worker.requests' => 'worker_request_count',
        ];

        foreach ($observers as $name => $observer) {
            $key = $statKeys[$name];

            if (isset($stats[$key])) {
                $observer->observe($stats[$key]);
            }
        }
    }

    /**
     * Observe current-worker coroutine metrics.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeCoroutines(array $observers): void
    {
        $stats = Coroutine::stats();

        if (isset($stats['coroutine_num'])) {
            $observers['hypervel.worker.coroutines']->observe($stats['coroutine_num']);
        }
    }
}
