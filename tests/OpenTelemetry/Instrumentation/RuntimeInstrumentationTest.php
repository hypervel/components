<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Container\Container;
use Hypervel\OpenTelemetry\Instrumentation\RuntimeInstrumentation;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Server;

class RuntimeInstrumentationTest extends TestCase
{
    private const array METRICS = [
        'php.memory.usage',
        'php.memory.peak_usage',
        'php.memory.limit',
        'php.gc.runs',
        'php.gc.collected',
        'php.gc.threshold',
        'php.gc.roots',
        'php.gc.collector_time',
        'php.gc.destructor_time',
        'php.gc.free_time',
        'process.cpu.time',
        'process.context_switches',
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
        'hypervel.server.connections',
        'hypervel.server.requests',
        'hypervel.server.tasks.active',
        'hypervel.server.task_queue.size',
        'hypervel.worker.requests',
        'hypervel.worker.coroutines',
    ];

    private InMemoryExporter $exporter;

    private ExportingReader $reader;

    private MeterProvider $meterProvider;

    protected function setUpInCoroutine(): void
    {
        $this->exporter = new InMemoryExporter;
        $this->reader = new ExportingReader($this->exporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->reader)
            ->build();
    }

    protected function tearDownInCoroutine(): void
    {
        $this->meterProvider->shutdown();
    }

    public function testRecordsAvailablePhpRuntimeMetricsFromCollectionTimeSnapshots(): void
    {
        $enabled = [
            'php.memory.usage',
            'php.memory.peak_usage',
            'php.memory.limit',
            'php.gc.runs',
            'php.gc.collected',
            'php.gc.threshold',
            'php.gc.roots',
            'php.gc.collector_time',
            'php.gc.destructor_time',
            'php.gc.free_time',
            'process.cpu.time',
            'process.context_switches',
            'hypervel.worker.coroutines',
        ];
        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $this->instrumentation($container, ProcessIdentity::cli())->register($this->options($enabled));

        $metrics = $this->collect();

        $this->assertSame($enabled, array_keys($metrics));
        $this->assertSame('By', $metrics['php.memory.usage']->unit);
        $this->assertGreaterThanOrEqual(
            0,
            $this->point($metrics['php.memory.usage'], ['memory.type' => 'emalloc'])->value,
        );
        $this->assertGreaterThanOrEqual(
            0,
            $this->point($metrics['php.memory.usage'], ['memory.type' => 'overhead'])->value,
        );
        $this->assertGreaterThanOrEqual(
            0,
            $this->point($metrics['php.memory.peak_usage'], ['memory.type' => 'emalloc'])->value,
        );
        $this->assertSame(1, $metrics['php.gc.runs']->data->dataPointCount());
        $this->assertSame(1, $metrics['php.gc.collected']->data->dataPointCount());
        $this->assertSame(1, $metrics['php.gc.threshold']->data->dataPointCount());
        $this->assertSame(1, $metrics['php.gc.roots']->data->dataPointCount());
        $this->assertSame(1, $metrics['php.gc.collector_time']->data->dataPointCount());
        $this->assertSame(1, $metrics['php.gc.destructor_time']->data->dataPointCount());
        $this->assertSame(1, $metrics['php.gc.free_time']->data->dataPointCount());
        $this->assertSame(2, $metrics['process.cpu.time']->data->dataPointCount());
        $this->assertGreaterThanOrEqual(
            1,
            $this->point($metrics['hypervel.worker.coroutines'])->value,
        );
    }

    public function testEventWorkerZeroResolvesServerOnceAndRecordsOneSnapshotPerCollection(): void
    {
        $snapshotCount = 0;
        $server = m::mock(Server::class);
        $server->shouldReceive('stats')->andReturnUsing(
            static function () use (&$snapshotCount): array {
                ++$snapshotCount;

                return [
                    'connection_num' => 11,
                    'request_count' => 120,
                    'tasking_num' => 3,
                    'task_queue_num' => 4,
                    'worker_request_count' => 25,
                ];
            },
        );
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with(Server::class)->andReturn($server);
        $enabled = [
            'hypervel.server.connections',
            'hypervel.server.requests',
            'hypervel.server.tasks.active',
            'hypervel.server.task_queue.size',
            'hypervel.worker.requests',
        ];
        $this->instrumentation($container, ProcessIdentity::eventWorker(0))
            ->register($this->options($enabled));

        $metrics = $this->collect();
        $this->collect();

        $this->assertSame(2, $snapshotCount);
        $this->assertSame($enabled, array_keys($metrics));
        $this->assertSame(11, $this->point($metrics['hypervel.server.connections'])->value);
        $this->assertSame(120, $this->point($metrics['hypervel.server.requests'])->value);
        $this->assertSame(3, $this->point($metrics['hypervel.server.tasks.active'])->value);
        $this->assertSame(4, $this->point($metrics['hypervel.server.task_queue.size'])->value);
        $this->assertSame(25, $this->point($metrics['hypervel.worker.requests'])->value);
    }

    public function testOtherServerWorkersRecordOnlyTheirWorkerLocalRequestCount(): void
    {
        $server = m::mock(Server::class);
        $server->shouldReceive('stats')->andReturn([
            'connection_num' => 11,
            'request_count' => 120,
            'worker_request_count' => 7,
        ]);
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with(Server::class)->andReturn($server);
        $enabled = [
            'hypervel.server.connections',
            'hypervel.server.requests',
            'hypervel.server.tasks.active',
            'hypervel.server.task_queue.size',
            'hypervel.worker.requests',
        ];
        $this->instrumentation($container, ProcessIdentity::taskWorker(1))
            ->register($this->options($enabled));

        $metrics = $this->collect();

        $this->assertSame(['hypervel.worker.requests'], array_keys($metrics));
        $this->assertSame(7, $this->point($metrics['hypervel.worker.requests'])->value);
    }

    public function testOpcacheMetricsExistOnlyWhenOpcacheIsActiveInEventWorkerZero(): void
    {
        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $enabled = [
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
        $this->instrumentation($container, ProcessIdentity::eventWorker(0))
            ->register($this->options($enabled));

        $metrics = $this->collect();
        $available = function_exists('opcache_get_status') && opcache_get_status(false) !== false;

        $this->assertSame($available ? $enabled : [], array_keys($metrics));
    }

    public function testDisabledMetricsDoNotResolveTheMeterOrServer(): void
    {
        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $meterProvider = m::mock(MeterProviderInterface::class);
        $meterProvider->shouldNotReceive('getMeter');

        (new RuntimeInstrumentation(
            $container,
            $meterProvider,
            ProcessIdentity::eventWorker(0),
        ))->register($this->options([]));

        $this->addToAssertionCount(1);
    }

    #[DataProvider('metricProvider')]
    public function testEachMetricSwitchCreatesOnlyItsOwnInstrument(string $name): void
    {
        $usesServer = str_starts_with($name, 'hypervel.server.')
            || $name === 'hypervel.worker.requests';
        $processIdentity = $usesServer || str_starts_with($name, 'php.opcache.')
            ? ProcessIdentity::eventWorker(0)
            : ProcessIdentity::cli();
        $container = m::mock(Container::class);

        if ($usesServer) {
            $server = m::mock(Server::class);
            $server->shouldReceive('stats')->andReturn([
                'connection_num' => 1,
                'request_count' => 1,
                'tasking_num' => 1,
                'task_queue_num' => 1,
                'worker_request_count' => 1,
            ]);
            $container->shouldReceive('make')->with(Server::class)->andReturn($server);
        } else {
            $container->shouldNotReceive('make');
        }

        $this->instrumentation($container, $processIdentity)
            ->register($this->options([$name]));

        $metrics = $this->collect();
        $available = ! str_starts_with($name, 'php.opcache.')
            || (function_exists('opcache_get_status') && opcache_get_status(false) !== false);

        $this->assertSame($available ? [$name] : [], array_keys($metrics));
    }

    /**
     * Return every configurable runtime metric.
     *
     * @return iterable<string, array{string}>
     */
    public static function metricProvider(): iterable
    {
        foreach (self::METRICS as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Create runtime instrumentation.
     */
    private function instrumentation(
        Container $container,
        ProcessIdentity $processIdentity,
    ): RuntimeInstrumentation {
        return new RuntimeInstrumentation(
            $container,
            $this->meterProvider,
            $processIdentity,
        );
    }

    /**
     * Return normalized metric switches.
     *
     * @param list<string> $enabled
     * @return array{metrics: array<string, bool>}
     */
    private function options(array $enabled): array
    {
        $metrics = [];

        foreach (self::METRICS as $name) {
            $metrics[$name] = in_array($name, $enabled, true);
        }

        return ['metrics' => $metrics];
    }

    /**
     * Collect and index one export by metric name.
     *
     * @return array<string, Metric>
     */
    private function collect(): array
    {
        $this->reader->collect();
        $indexed = [];

        foreach ($this->exporter->collect(true) as $metric) {
            $indexed[$metric->name] = $metric;
        }

        return $indexed;
    }

    /**
     * Return one exact numeric point.
     *
     * @param array<string, string> $attributes
     */
    private function point(Metric $metric, array $attributes = []): NumberDataPoint
    {
        $this->assertContainsOnlyInstancesOf(
            NumberDataPoint::class,
            $metric->data instanceof Sum || $metric->data instanceof Gauge
                ? $metric->data->dataPoints
                : [],
        );

        if ($metric->data instanceof Sum || $metric->data instanceof Gauge) {
            foreach ($metric->data->dataPoints as $point) {
                if ($this->hasAttributes($point, $attributes)) {
                    return $point;
                }
            }
        }

        $this->fail('The expected metric point was not exported.');
    }

    /**
     * Determine whether a point has every expected attribute.
     *
     * @param array<string, string> $attributes
     */
    private function hasAttributes(NumberDataPoint $point, array $attributes): bool
    {
        foreach ($attributes as $name => $value) {
            if ($point->attributes->get($name) !== $value) {
                return false;
            }
        }

        return true;
    }
}
