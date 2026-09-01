<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Process\Process;

class PublishedConfigurationTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [OpenTelemetryServiceProvider::class];
    }

    public function testPublishedDefaultsExposeTheCompleteMetricSurface(): void
    {
        $configuration = $this->publishedConfiguration();
        $metrics = [];

        foreach ($configuration['instrumentation'] as $options) {
            if (is_array($options) && is_array($options['metrics'] ?? null)) {
                foreach ($options['metrics'] as $name => $enabled) {
                    $metrics[$name] = $enabled;
                }
            }
        }

        ksort($metrics);

        $this->assertSame([
            'db.client.connection.count' => true,
            'db.client.connection.max' => true,
            'db.client.connection.pending_requests' => true,
            'db.client.operation.duration' => true,
            'http.client.request.duration' => true,
            'http.server.active_requests' => false,
            'http.server.request.duration' => true,
            'hypervel.cache.operations' => true,
            'hypervel.console.command.duration' => true,
            'hypervel.exceptions' => true,
            'hypervel.object_pool.max' => true,
            'hypervel.object_pool.objects' => true,
            'hypervel.object_pool.pending_requests' => true,
            'hypervel.queue.jobs' => false,
            'hypervel.scheduler.task.duration' => true,
            'hypervel.scheduler.task.executions' => true,
            'hypervel.server.connections' => true,
            'hypervel.server.requests' => true,
            'hypervel.server.task_queue.size' => true,
            'hypervel.server.tasks.active' => true,
            'hypervel.view.render.duration' => true,
            'hypervel.websocket.active_connections' => true,
            'hypervel.websocket.message.duration' => true,
            'hypervel.websocket.messages' => true,
            'hypervel.worker.coroutines' => true,
            'hypervel.worker.requests' => true,
            'messaging.client.consumed.messages' => true,
            'messaging.client.operation.duration' => true,
            'messaging.client.sent.messages' => true,
            'messaging.process.duration' => true,
            'php.gc.collected' => true,
            'php.gc.collector_time' => true,
            'php.gc.destructor_time' => true,
            'php.gc.free_time' => true,
            'php.gc.roots' => true,
            'php.gc.runs' => true,
            'php.gc.threshold' => true,
            'php.memory.limit' => true,
            'php.memory.peak_usage' => true,
            'php.memory.usage' => true,
            'php.opcache.cached_scripts' => true,
            'php.opcache.hit_rate' => true,
            'php.opcache.hits' => true,
            'php.opcache.interned_strings.count' => true,
            'php.opcache.interned_strings.memory_free' => true,
            'php.opcache.interned_strings.memory_used' => true,
            'php.opcache.memory_free' => true,
            'php.opcache.memory_used' => true,
            'php.opcache.memory_wasted' => true,
            'php.opcache.misses' => true,
            'process.context_switches' => true,
            'process.cpu.time' => true,
            'rpc.client.call.duration' => true,
            'rpc.server.call.duration' => true,
        ], $metrics);
    }

    public function testPublishedDefaultsUseOtlpProtobufTransport(): void
    {
        $configuration = $this->runConfigurationProcess([]);

        $this->assertSame(['otlp'], $configuration['metrics']['exporter']);
        $this->assertSame(['otlp'], $configuration['traces']['exporter']);
        $this->assertSame(['otlp'], $configuration['logs']['exporter']);
        $this->assertSame(
            'http/protobuf',
            $configuration['exporters']['otlp']['protocol'],
        );
    }

    public function testPublishedConfigurationUsesSdkTypedResolution(): void
    {
        $configuration = $this->withEnvironment([
            'OTEL_SDK_DISABLED' => 'true',
            'OTEL_PHP_INTERNAL_METRICS_ENABLED' => 'true',
            'OTEL_SERVICE_NAME' => 'orders',
            'OTEL_RESOURCE_ATTRIBUTES' => 'service.name=ignored,service.version=1.2.3,acme.region=west',
            'OTEL_PROPAGATORS' => 'tracecontext,baggage',
            'OTEL_EXPERIMENTAL_RESPONSE_PROPAGATORS' => 'traceresponse',
            'OTEL_METRICS_EXPORTER' => 'console',
            'OTEL_METRIC_EXPORT_INTERVAL' => '1234',
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE' => 'delta',
            'OTEL_TRACES_SAMPLER' => 'traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '0.25',
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/json',
            'OTEL_EXPORTER_OTLP_HEADERS' => 'authorization=secret,tenant=alpha',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'https://collector.test/traces',
            'OTEL_EXPORTER_OTLP_TRACES_HEADERS' => 'trace=one',
            'OTEL_EXPORTER_OTLP_TRACES_TIMEOUT' => '4321',
            'OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS' => 'get,PURGE',
        ], fn (): array => $this->publishedConfiguration());

        $this->assertFalse($configuration['enabled']);
        $this->assertTrue($configuration['internal_metrics']);
        $this->assertSame([
            'service.name' => 'orders',
            'service.version' => '1.2.3',
            'acme.region' => 'west',
        ], $configuration['resource_attributes']);
        $this->assertSame(['tracecontext', 'baggage'], $configuration['propagators']);
        $this->assertSame(['traceresponse'], $configuration['response_propagators']);
        $this->assertSame(['console'], $configuration['metrics']['exporter']);
        $this->assertSame(1234, $configuration['metrics']['export_interval']);
        $this->assertSame('delta', $configuration['metrics']['temporality']);
        $this->assertSame('traceidratio', $configuration['traces']['sampler']);
        $this->assertSame(0.25, $configuration['traces']['sampler_arg']);
        $this->assertSame('http/json', $configuration['exporters']['otlp']['protocol']);
        $this->assertSame([
            'authorization' => 'secret',
            'tenant' => 'alpha',
        ], $configuration['exporters']['otlp']['headers']);
        $this->assertSame(
            'https://collector.test/traces',
            $configuration['exporters']['otlp']['traces_endpoint'],
        );
        $this->assertSame(
            ['trace' => 'one'],
            $configuration['exporters']['otlp']['traces_headers'],
        );
        $this->assertSame(4321, $configuration['exporters']['otlp']['traces_timeout']);
        $this->assertNull($configuration['exporters']['otlp']['metrics_endpoint']);
        $this->assertNull($configuration['exporters']['otlp']['logs_client_key']);
        $this->assertSame(
            ['get', 'PURGE'],
            $configuration['instrumentation'][
                \Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation::class
            ]['known_methods'],
        );
    }

    public function testPublishedConfigurationUsesPhpIniResolution(): void
    {
        $configuration = $this->runConfigurationProcess([
            '-d',
            'OTEL_SERVICE_NAME=from-ini',
        ]);

        $this->assertSame(
            'from-ini',
            $configuration['resource_attributes']['service.name'],
        );
    }

    public function testNonStandardTruthyDisabledValuesLeaveTheSdkEnabled(): void
    {
        foreach (['1', 'yes', 'on'] as $value) {
            $configuration = $this->withEnvironment(
                ['OTEL_SDK_DISABLED' => $value],
                fn (): array => $this->publishedConfiguration(),
            );

            $this->assertTrue($configuration['enabled']);
        }
    }

    public function testPublishedConfigurationOmitsSdkSettingsItCannotEnforce(): void
    {
        $configuration = $this->publishedConfiguration();

        $this->assertArrayNotHasKey('export_timeout', $configuration['metrics']);
        $this->assertArrayNotHasKey('export_timeout', $configuration['traces']);
        $this->assertArrayNotHasKey('export_timeout', $configuration['logs']);
        $this->assertArrayNotHasKey(
            'metrics_default_histogram_aggregation',
            $configuration['exporters']['otlp'],
        );
    }

    #[WithConfig('opentelemetry.enabled', false)]
    public function testConfigurationCacheFreezesSdkResolvedValuesUntilRebuilt(): void
    {
        $files = new Filesystem;
        $publishedConfigurationPath = $this->app->configPath('opentelemetry.php');

        try {
            $this->artisan('vendor:publish', ['--tag' => 'opentelemetry-config'])
                ->assertSuccessful();

            $this->withEnvironment([
                'OTEL_SERVICE_NAME' => 'cached-one',
                'OTEL_SDK_DISABLED' => 'true',
            ], function (): void {
                $this->artisan('config:cache')->assertSuccessful();
            });

            $cached = require $this->app->getCachedConfigPath();
            $this->assertSame(
                'cached-one',
                $cached['opentelemetry']['resource_attributes']['service.name'],
            );

            $this->withEnvironment([
                'OTEL_SERVICE_NAME' => 'cached-two',
                'OTEL_SDK_DISABLED' => 'true',
            ], function (): void {
                $cached = require $this->app->getCachedConfigPath();
                $this->assertSame(
                    'cached-one',
                    $cached['opentelemetry']['resource_attributes']['service.name'],
                );

                $this->artisan('config:cache')->assertSuccessful();
            });

            $rebuilt = require $this->app->getCachedConfigPath();
            $this->assertSame(
                'cached-two',
                $rebuilt['opentelemetry']['resource_attributes']['service.name'],
            );
        } finally {
            $files->delete($publishedConfigurationPath);
            $files->delete($this->app->getCachedConfigPath());
        }
    }

    /**
     * Load the published package configuration.
     *
     * @return array<string, mixed>
     */
    private function publishedConfiguration(): array
    {
        return require dirname(__DIR__, 2) . '/src/opentelemetry/config/opentelemetry.php';
    }

    /**
     * Run a callback with temporary environment variables.
     *
     * @template T
     * @param array<string, string> $variables
     * @param Closure(): T $callback
     * @return T
     */
    private function withEnvironment(array $variables, Closure $callback): mixed
    {
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = [
                'environment' => getenv($name),
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
            putenv("{$name}={$value}");
            $_SERVER[$name] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $name => $state) {
                $state['environment'] === false
                    ? putenv($name)
                    : putenv("{$name}={$state['environment']}");

                if ($state['server_exists']) {
                    $_SERVER[$name] = $state['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    /**
     * Resolve the published configuration in an isolated PHP process.
     *
     * @param list<string> $phpArguments
     * @return array<string, mixed>
     */
    private function runConfigurationProcess(array $phpArguments): array
    {
        $script = <<<'PHP'
        require $argv[1];

        $configuration = require $argv[2];

        echo json_encode($configuration, JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([
            PHP_BINARY,
            ...$phpArguments,
            '-r',
            $script,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/src/opentelemetry/config/opentelemetry.php',
        ], env: [
            'COMPOSER_DEV_MODE' => false,
            'OTEL_TRACES_EXPORTER' => false,
            'OTEL_METRICS_EXPORTER' => false,
            'OTEL_LOGS_EXPORTER' => false,
            'OTEL_SERVICE_NAME' => false,
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
