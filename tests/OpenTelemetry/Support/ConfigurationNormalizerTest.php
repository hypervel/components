<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Closure;
use Hypervel\OpenTelemetry\Instrumentation\DatabaseInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\EventInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\QueueInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\RuntimeInstrumentation;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\InstrumentationOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigurationNormalizerTest extends TestCase
{
    public function testFillsReplaceableSignalAndExporterRecordDefaults(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics'] = ['exporter' => 'console'];
        $configuration['traces'] = ['exporter' => ['otlp'], 'sampler' => 'always_off'];
        $configuration['logs'] = ['exporter' => ['none'], 'schedule_delay' => 0];
        $configuration['exporters']['otlp'] = ['endpoint' => 'https://collector.test'];

        $normalized = (new ConfigurationNormalizer)->normalize($configuration);

        $this->assertSame('console', $normalized['metrics']['exporter']);
        $this->assertSame(60000, $normalized['metrics']['export_interval']);
        $this->assertSame('otlp', $normalized['traces']['exporter']);
        $this->assertSame('always_off', $normalized['traces']['sampler']);
        $this->assertSame(2048, $normalized['traces']['max_queue_size']);
        $this->assertSame('none', $normalized['logs']['exporter']);
        $this->assertSame(0, $normalized['logs']['schedule_delay']);
        $this->assertSame('otlp', $normalized['exporters']['otlp']['driver']);
        $this->assertSame('https://collector.test', $normalized['exporters']['otlp']['endpoint']);
        $this->assertSame('http/protobuf', $normalized['exporters']['otlp']['protocol']);
    }

    public function testRatioSamplerAcceptsIntegerOrFloatAndValidatesItsRange(): void
    {
        $configuration = $this->configuration();
        $configuration['traces']['sampler'] = 'traceidratio';
        $configuration['traces']['sampler_arg'] = 1;

        $normalized = (new ConfigurationNormalizer)->normalize($configuration);

        $this->assertSame(1.0, $normalized['traces']['sampler_arg']);

        $configuration['traces']['sampler_arg'] = 1.1;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be between 0.0 and 1.0');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    public function testSamplerArgumentIsStructurallyNormalizedWhenItsValueIsUnused(): void
    {
        $configuration = $this->configuration();
        $configuration['traces']['sampler'] = 'always_on';
        $configuration['traces']['sampler_arg'] = 2;

        $normalized = (new ConfigurationNormalizer)->normalize($configuration);

        $this->assertSame(2.0, $normalized['traces']['sampler_arg']);
    }

    public function testDisabledSignalsDoNotValidateUnusedCadences(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['exporter'] = 'none';
        $configuration['metrics']['export_interval'] = 0;
        $configuration['traces']['exporter'] = 'none';
        $configuration['traces']['schedule_delay'] = -1;
        $configuration['logs']['exporter'] = 'none';
        $configuration['logs']['schedule_delay'] = 0;

        $normalized = (new ConfigurationNormalizer)->normalize($configuration);

        $this->assertSame(0, $normalized['metrics']['export_interval']);
        $this->assertSame(-1, $normalized['traces']['schedule_delay']);
        $this->assertSame(0, $normalized['logs']['schedule_delay']);
    }

    public function testEnabledSignalRequiresOneDefinedExporter(): void
    {
        $configuration = $this->configuration();
        $configuration['traces']['exporter'] = ['otlp', 'console'];

        try {
            (new ConfigurationNormalizer)->normalize($configuration);
            $this->fail('Multiple exporters were accepted for one signal.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must contain exactly one exporter', $exception->getMessage());
        }

        $configuration['traces']['exporter'] = 'missing';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exporter [missing] configured for [traces] is not defined');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    public function testActiveCadenceAndBatchSettingsAreValidated(): void
    {
        $configuration = $this->configuration();
        $configuration['logs']['max_export_batch_size'] = 2049;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_export_batch_size] must not exceed [max_queue_size]');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    public function testInvalidExemplarFilterFailsBeforeProviderConstruction(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['exemplar_filter'] = 'invalid';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported OpenTelemetry exemplar filter [invalid]');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    public function testProviderOverridesIgnoreUnusedSemanticSettingsButKeepActiveCadences(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['provider'] = self::class;
        $configuration['metrics']['exporter'] = 'missing-metrics';
        $configuration['metrics']['exemplar_filter'] = 'unused';
        $configuration['traces']['provider'] = self::class;
        $configuration['traces']['exporter'] = 'missing-traces';
        $configuration['traces']['sampler'] = 'traceidratio';
        $configuration['traces']['sampler_arg'] = 2;
        $configuration['traces']['max_queue_size'] = -1;
        $configuration['traces']['max_export_batch_size'] = 0;
        $configuration['logs']['provider'] = self::class;
        $configuration['logs']['exporter'] = 'missing-logs';
        $configuration['logs']['max_queue_size'] = -1;
        $configuration['logs']['max_export_batch_size'] = 0;

        $normalized = (new ConfigurationNormalizer)->normalize($configuration);

        $this->assertSame('unused', $normalized['metrics']['exemplar_filter']);
        $this->assertSame(2.0, $normalized['traces']['sampler_arg']);
        $this->assertSame(-1, $normalized['traces']['max_queue_size']);
        $this->assertSame(0, $normalized['logs']['max_export_batch_size']);

        $configuration['traces']['schedule_delay'] = 0;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('opentelemetry.traces.schedule_delay');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    public function testFillsBuiltInInstrumentationDefaultsAfterClassEntryReplacement(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            HttpServerInstrumentation::class => [
                'except_paths' => ['health'],
                'metrics' => false,
            ],
            DatabaseInstrumentation::class => true,
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertSame([
            'traces' => true,
            'known_methods' => ConfigurationNormalizer::DEFAULT_HTTP_METHODS,
            'except_paths' => ['health'],
            'except_methods' => [],
            'user_context' => false,
            'url_query' => false,
            'sensitive_query_parameters' => [],
            'sensitive_headers' => [],
            'request_headers' => [],
            'response_headers' => [],
            'metrics' => false,
        ], $instrumentation[HttpServerInstrumentation::class]);
        $this->assertTrue($instrumentation[DatabaseInstrumentation::class]['traces']);
        $this->assertSame(500, $instrumentation[DatabaseInstrumentation::class]['query_text_max_length']);
    }

    public function testPartialBuiltInMetricMapOverridesOnlyNamedMetrics(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            HttpServerInstrumentation::class => [
                'metrics' => ['http.server.active_requests' => true],
            ],
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertSame([
            'http.server.request.duration' => true,
            'http.server.active_requests' => true,
        ], $instrumentation[HttpServerInstrumentation::class]['metrics']);
    }

    public function testPartialMetricMapDoesNotPruneMetricsOnlyInstrumentation(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            RuntimeInstrumentation::class => [
                'metrics' => ['php.memory.usage' => false],
            ],
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertIsArray($instrumentation[RuntimeInstrumentation::class]);
        $this->assertFalse($instrumentation[RuntimeInstrumentation::class]['metrics']['php.memory.usage']);
        $this->assertTrue($instrumentation[RuntimeInstrumentation::class]['metrics']['php.memory.peak_usage']);
    }

    public function testThirdPartyMetricMapRemainsExact(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            'Acme\TelemetryInstrumentation' => [
                'metrics' => ['acme.operations' => true],
            ],
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertSame(
            ['acme.operations' => true],
            $instrumentation['Acme\TelemetryInstrumentation']['metrics'],
        );
    }

    public function testBuiltInMetricBooleanShorthandsRemainBooleans(): void
    {
        foreach ([true, false] as $enabled) {
            $configuration = $this->configuration();
            $configuration['instrumentation'] = [
                HttpServerInstrumentation::class => ['metrics' => $enabled],
            ];

            $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

            $this->assertSame($enabled, $instrumentation[HttpServerInstrumentation::class]['metrics']);
        }
    }

    public function testPublishedInstrumentationMatchesBuiltInDefaults(): void
    {
        $name = 'OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS';
        $environment = getenv($name);
        $serverValueExists = array_key_exists($name, $_SERVER);
        $serverValue = $_SERVER[$name] ?? null;

        try {
            putenv($name);
            unset($_SERVER[$name]);

            $published = require dirname(__DIR__, 3) . '/src/opentelemetry/config/opentelemetry.php';

            $this->assertSame(
                (new ConfigurationNormalizerTestDefaults)->instrumentationDefaults(),
                $published['instrumentation'],
            );
        } finally {
            $environment === false ? putenv($name) : putenv("{$name}={$environment}");

            if ($serverValueExists) {
                $_SERVER[$name] = $serverValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    public function testSignalExportersPruneOnlyInstrumentationsWithoutSurvivingOutput(): void
    {
        $configuration = $this->configuration();
        $configuration['traces']['exporter'] = 'none';
        $configuration['instrumentation'] = [
            HttpServerInstrumentation::class => ['traces' => true, 'metrics' => false],
            'Acme\TraceInstrumentation' => ['traces' => true, 'custom' => 'dropped'],
            'Acme\PropagationInstrumentation' => ['propagation' => true, 'custom' => 'retained'],
            'Acme\CustomInstrumentation' => ['custom' => 'retained'],
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertFalse($instrumentation[HttpServerInstrumentation::class]);
        $this->assertFalse($instrumentation['Acme\TraceInstrumentation']);
        $this->assertSame('retained', $instrumentation['Acme\PropagationInstrumentation']['custom']);
        $this->assertSame('retained', $instrumentation['Acme\CustomInstrumentation']['custom']);
    }

    public function testAllDisabledSignalsPruneEveryInstrumentationAfterStructuralNormalization(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['exporter'] = 'none';
        $configuration['traces']['exporter'] = 'none';
        $configuration['logs']['exporter'] = 'none';
        $configuration['instrumentation'] = [
            QueueInstrumentation::class => true,
            'Acme\PropagationInstrumentation' => ['propagation' => true],
            'Acme\CustomInstrumentation' => ['custom' => 'retained'],
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertFalse($instrumentation[QueueInstrumentation::class]);
        $this->assertFalse($instrumentation['Acme\PropagationInstrumentation']);
        $this->assertFalse($instrumentation['Acme\CustomInstrumentation']);
    }

    public function testLogsKeepOnlyHttpUserContextInstrumentationWhenOtherSignalsAreDisabled(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['exporter'] = 'none';
        $configuration['traces']['exporter'] = 'none';
        $configuration['instrumentation'] = [
            HttpServerInstrumentation::class => ['user_context' => true],
        ];

        $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

        $this->assertFalse($instrumentation[HttpServerInstrumentation::class]['traces']);
        $this->assertFalse($instrumentation[HttpServerInstrumentation::class]['metrics']);
        $this->assertTrue($instrumentation[HttpServerInstrumentation::class]['user_context']);
    }

    public function testInstrumentationOptionsSupportMetricBooleanAndNamedMapForms(): void
    {
        $allMetrics = new InstrumentationOptions(['traces' => true, 'metrics' => true]);
        $namedMetrics = new InstrumentationOptions([
            'metrics' => ['enabled.metric' => true, 'disabled.metric' => false],
        ]);

        $this->assertTrue($allMetrics->enabled('traces'));
        $this->assertTrue($allMetrics->metricEnabled('anything'));
        $this->assertTrue($namedMetrics->metricEnabled('enabled.metric'));
        $this->assertFalse($namedMetrics->metricEnabled('disabled.metric'));
        $this->assertFalse($namedMetrics->metricEnabled('missing.metric'));
    }

    public function testRejectsInvalidInstrumentationMetricAndLengthOptions(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            DatabaseInstrumentation::class => [
                'metrics' => ['db.client.operation.duration' => 'yes'],
            ],
        ];

        try {
            (new ConfigurationNormalizer)->normalize($configuration);
            $this->fail('An invalid instrumentation metric switch was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('map of metric names to booleans', $exception->getMessage());
        }

        $configuration['instrumentation'][DatabaseInstrumentation::class] = [
            'query_text_max_length' => 0,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('query_text_max_length] must be a positive integer');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    public function testEventInstrumentationAcceptsOnlyExactEventNames(): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            EventInstrumentation::class => ['events' => ['orders.*']],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain exact event names without wildcards');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    #[DataProvider('enabledQueueDepthMetricProvider')]
    public function testQueueDepthMetricRequiresAtLeastOneConfiguredTarget(array|bool $metrics): void
    {
        $configuration = $this->configuration();
        $configuration['instrumentation'] = [
            QueueInstrumentation::class => [
                'traces' => false,
                'propagation' => false,
                'depth_queues' => [],
                'metrics' => $metrics,
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('depth_queues] must contain at least one queue');

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    /**
     * @return iterable<string, array{array<string, bool>|bool}>
     */
    public static function enabledQueueDepthMetricProvider(): iterable
    {
        yield 'all metrics shorthand' => [true];
        yield 'named depth metric' => [['hypervel.queue.jobs' => true]];
    }

    public function testDisabledMetricsIgnoreEmptyQueueDepthTargets(): void
    {
        foreach ([true, ['hypervel.queue.jobs' => true]] as $metrics) {
            $configuration = $this->configuration();
            $configuration['metrics']['exporter'] = 'none';
            $configuration['instrumentation'] = [
                QueueInstrumentation::class => [
                    'traces' => false,
                    'propagation' => true,
                    'depth_queues' => [],
                    'metrics' => $metrics,
                ],
            ];

            $instrumentation = (new ConfigurationNormalizer)->normalize($configuration)['instrumentation'];

            $this->assertFalse($instrumentation[QueueInstrumentation::class]['metrics']);
        }
    }

    public function testPublishedHttpMethodDefaultMatchesItsOwningConstantAndStandardOverrideReplacesIt(): void
    {
        $name = 'OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS';
        $environment = getenv($name);
        $serverValueExists = array_key_exists($name, $_SERVER);
        $serverValue = $_SERVER[$name] ?? null;

        try {
            putenv($name);
            unset($_SERVER[$name]);

            $defaults = require dirname(__DIR__, 3) . '/src/opentelemetry/config/opentelemetry.php';

            $this->assertSame(
                ConfigurationNormalizer::DEFAULT_HTTP_METHODS,
                $defaults['instrumentation'][HttpServerInstrumentation::class]['known_methods'],
            );

            putenv("{$name}=get,PURGE");

            $overridden = require dirname(__DIR__, 3) . '/src/opentelemetry/config/opentelemetry.php';

            $this->assertSame(
                ['get', 'PURGE'],
                $overridden['instrumentation'][HttpServerInstrumentation::class]['known_methods'],
            );
            $this->assertSame(
                ['get', 'PURGE'],
                $overridden['instrumentation'][HttpClientInstrumentation::class]['known_methods'],
            );
        } finally {
            $environment === false ? putenv($name) : putenv("{$name}={$environment}");

            if ($serverValueExists) {
                $_SERVER[$name] = $serverValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    /**
     * @param Closure(array<string, mixed>): void $mutate
     */
    #[DataProvider('malformedStructuralValueProvider')]
    public function testMalformedStructuralValuesFailWhenTheirSemanticsAreUnused(Closure $mutate): void
    {
        $configuration = $this->configuration();
        $mutate($configuration);

        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationNormalizer)->normalize($configuration);
    }

    /**
     * @return iterable<string, array{Closure(array<string, mixed>): void}>
     */
    public static function malformedStructuralValueProvider(): iterable
    {
        yield 'disabled cadence' => [function (array &$configuration): void {
            $configuration['metrics']['exporter'] = 'none';
            $configuration['metrics']['export_interval'] = 'disabled';
        }];

        yield 'provider-owned queue size' => [function (array &$configuration): void {
            $configuration['logs']['provider'] = self::class;
            $configuration['logs']['max_queue_size'] = 'ignored';
        }];

        yield 'provider-owned sampler argument' => [function (array &$configuration): void {
            $configuration['traces']['provider'] = self::class;
            $configuration['traces']['sampler_arg'] = 'ignored';
        }];

        yield 'provider-owned metric views' => [function (array &$configuration): void {
            $configuration['metrics']['provider'] = self::class;
            $configuration['metrics']['views'] = 'ignored';
        }];

        yield 'disabled queue-depth structure' => [function (array &$configuration): void {
            $configuration['metrics']['exporter'] = 'none';
            $configuration['instrumentation'] = [
                QueueInstrumentation::class => [
                    'depth_queues' => ['redis' => 'default'],
                ],
            ];
        }];
    }

    /**
     * Return a complete configuration fixture.
     *
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        return [
            'enabled' => true,
            'internal_metrics' => false,
            'service' => [
                'name' => 'test',
                'version' => null,
                'environment' => null,
                'instance_id' => null,
            ],
            'resource_attributes' => [],
            'propagators' => ['tracecontext'],
            'response_propagators' => ['none'],
            'metrics' => [
                'provider' => null,
                'exporter' => ['otlp'],
                'export_interval' => 60000,
                'temporality' => 'cumulative',
                'exemplar_filter' => 'trace_based',
                'views' => [],
            ],
            'traces' => [
                'provider' => null,
                'exporter' => ['otlp'],
                'sampler' => 'parentbased_always_on',
                'sampler_arg' => 1.0,
                'schedule_delay' => 5000,
                'max_queue_size' => 2048,
                'max_export_batch_size' => 512,
                'processors' => [],
            ],
            'logs' => [
                'provider' => null,
                'exporter' => ['otlp'],
                'schedule_delay' => 1000,
                'max_queue_size' => 2048,
                'max_export_batch_size' => 512,
                'processors' => [],
            ],
            'log_context' => [
                'enabled' => false,
                'trace_id_key' => 'trace_id',
                'span_id_key' => 'span_id',
            ],
            'server_processes' => ['except' => []],
            'exporters' => [
                'otlp' => ['driver' => 'otlp'],
                'console' => ['driver' => 'console'],
            ],
        ];
    }
}

class ConfigurationNormalizerTestDefaults extends ConfigurationNormalizer
{
    /**
     * Expose built-in instrumentation defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    public function instrumentationDefaults(): array
    {
        return parent::instrumentationDefaults();
    }
}
