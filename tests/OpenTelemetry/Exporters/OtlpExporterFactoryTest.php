<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Exporters;

use Hypervel\OpenTelemetry\Exporters\OtlpExporterFactory;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Process\Process;

class OtlpExporterFactoryTest extends TestCase
{
    public function testSignalOverridesTakePrecedenceAndSharedEndpointsReceiveTheStandardPath(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['protocol'] = Protocols::HTTP_PROTOBUF;

        $this->assertSame([
            'endpoint' => 'http://collector.test:4318/base/v1/traces',
            'protocol' => Protocols::HTTP_PROTOBUF,
            'headers' => ['shared' => 'header'],
            'compression' => 'none',
            'timeout' => 10000,
            'certificate' => null,
            'client_certificate' => null,
            'client_key' => null,
            'max_retries' => 3,
        ], $factory->resolveSignalConfig($config, Signals::TRACE));

        $config['metrics_endpoint'] = 'https://metrics.test/custom';
        $config['metrics_protocol'] = Protocols::HTTP_JSON;
        $config['metrics_headers'] = ['signal' => 'metrics'];
        $config['metrics_compression'] = 'gzip';
        $config['metrics_timeout'] = 2500;
        $config['metrics_certificate'] = '/certificates/ca.pem';
        $config['metrics_client_certificate'] = '/certificates/client.pem';
        $config['metrics_client_key'] = '/certificates/client.key';

        $this->assertSame([
            'endpoint' => 'https://metrics.test/custom',
            'protocol' => Protocols::HTTP_JSON,
            'headers' => ['signal' => 'metrics'],
            'compression' => 'gzip',
            'timeout' => 2500,
            'certificate' => '/certificates/ca.pem',
            'client_certificate' => '/certificates/client.pem',
            'client_key' => '/certificates/client.key',
            'max_retries' => 3,
        ], $factory->resolveSignalConfig($config, Signals::METRICS));
    }

    public function testClientOptionsMapTimeoutCaAndMutualTlsFiles(): void
    {
        $factory = new TestOtlpExporterFactory;

        $this->assertSame([
            'timeout' => 10,
            'verify' => true,
        ], $factory->resolveClientOptions($this->config()));

        $config = $this->config();
        $config['timeout'] = 2500;
        $config['certificate'] = '/certificates/ca.pem';
        $config['client_certificate'] = '/certificates/client.pem';
        $config['client_key'] = '/certificates/client.key';

        $this->assertSame([
            'timeout' => 2.5,
            'verify' => '/certificates/ca.pem',
            'cert' => '/certificates/client.pem',
            'ssl_key' => '/certificates/client.key',
        ], $factory->resolveClientOptions($config));
    }

    public function testClientsAreSharedOnlyWhenTimeoutAndTlsSettingsMatch(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();

        $first = $factory->resolveClient($config);
        $second = $factory->resolveClient($config + ['endpoint' => 'https://another.test']);
        $different = $config;
        $different['timeout'] = 5000;
        $third = $factory->resolveClient($different);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $third);
        $this->assertCount(2, $factory->createdOptions);
    }

    public function testProtocolAndTemporalityMappingsRejectUnsupportedValues(): void
    {
        $factory = new TestOtlpExporterFactory;

        $this->assertSame(ContentTypes::PROTOBUF, $factory->resolveContentType(Protocols::HTTP_PROTOBUF));
        $this->assertSame(ContentTypes::JSON, $factory->resolveContentType(Protocols::HTTP_JSON));
        $this->assertSame(Temporality::CUMULATIVE, $factory->resolveTemporality('cumulative'));
        $this->assertSame(Temporality::DELTA, $factory->resolveTemporality('DELTA'));
        $this->assertNull($factory->resolveTemporality('lowmemory'));

        try {
            $factory->resolveContentType(Protocols::GRPC);
            $this->fail('An unsupported protocol was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Unsupported OTLP [traces] protocol [grpc]. Use [http/protobuf] or [http/json].',
                $exception->getMessage(),
            );
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported metric temporality [unknown].');

        $factory->resolveTemporality('unknown');
    }

    public function testEverySignalUsesTheExplicitSharedPsrClient(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['temporality'] = 'cumulative';

        $this->assertInstanceOf(SpanExporter::class, $factory->spanExporter($config));
        $this->assertInstanceOf(MetricExporter::class, $factory->metricExporter($config));
        $this->assertInstanceOf(LogsExporter::class, $factory->logExporter($config));
        $this->assertCount(1, $factory->createdOptions);
    }

    #[RequiresPhpExtension('protobuf')]
    public function testEverySignalCreatesANativeProtobufExporter(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['protocol'] = Protocols::HTTP_PROTOBUF;
        $config['temporality'] = 'cumulative';

        $this->assertInstanceOf(SpanExporter::class, $factory->spanExporter($config));
        $this->assertInstanceOf(MetricExporter::class, $factory->metricExporter($config));
        $this->assertInstanceOf(LogsExporter::class, $factory->logExporter($config));
        $this->assertCount(1, $factory->createdOptions);
    }

    public function testMetricAndLogExportersRouteSignalSpecificValidation(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['temporality'] = 'cumulative';
        $config['metrics_compression'] = 'brotli';

        try {
            $factory->metricExporter($config);
            $this->fail('Unsupported metric compression was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Unsupported OTLP [metrics] compression [brotli]. Use [none] or [gzip].',
                $exception->getMessage(),
            );
        }

        $config = $this->config();
        $config['logs_compression'] = 'brotli';

        try {
            $factory->logExporter($config);
            $this->fail('Unsupported log compression was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Unsupported OTLP [logs] compression [brotli]. Use [none] or [gzip].',
                $exception->getMessage(),
            );
        }
    }

    public function testProtobufRequiresTheExtensionAfterFinalSignalValidation(): void
    {
        $script = <<<'PHP'
use Hypervel\OpenTelemetry\Exporters\OtlpExporterFactory;
use OpenTelemetry\Contrib\Otlp\Protocols;

require $argv[1];

$configuration = static fn (string $protocol): array => [
    'endpoint' => 'http://collector.test:4318',
    'protocol' => $protocol,
    'headers' => [],
    'compression' => 'none',
    'timeout' => 10000,
    'certificate' => null,
    'client_certificate' => null,
    'client_key' => null,
    'max_retries' => 3,
];

$run = static function (string $case, array $config): void {
    try {
        (new OtlpExporterFactory)->spanExporter($config);
        echo "{$case}=success", PHP_EOL;
    } catch (\Throwable $exception) {
        echo $case, '=', $exception::class, ':', $exception->getMessage(), PHP_EOL;
    }
};

echo 'protobuf_loaded=', extension_loaded('protobuf') ? 'true' : 'false', PHP_EOL;

$run('shared_protobuf', $configuration(Protocols::HTTP_PROTOBUF));

$config = $configuration(Protocols::HTTP_JSON);
$config['traces_protocol'] = Protocols::HTTP_PROTOBUF;
$run('trace_protobuf_override', $config);

$config = $configuration(Protocols::HTTP_PROTOBUF);
$config['traces_protocol'] = Protocols::HTTP_JSON;
$run('trace_json_override', $config);

$config = $configuration(Protocols::HTTP_PROTOBUF);
$config['compression'] = 'brotli';
$run('invalid_compression', $config);
PHP;
        $process = new Process([
            PHP_BINARY,
            '-n',
            '-r',
            $script,
            realpath(__DIR__ . '/../../../vendor/autoload.php'),
        ]);
        $process->run();
        $diagnostic = "STDOUT:\n{$process->getOutput()}\nSTDERR:\n{$process->getErrorOutput()}";

        $this->assertSame(0, $process->getExitCode(), $diagnostic);
        $this->assertSame('', $process->getErrorOutput(), $diagnostic);
        $this->assertSame(<<<'OUTPUT'
protobuf_loaded=false
shared_protobuf=RuntimeException:The "protobuf" PHP extension is required to export the OTLP [traces] signal using [http/protobuf]. Install ext-protobuf or select [http/json].
trace_protobuf_override=RuntimeException:The "protobuf" PHP extension is required to export the OTLP [traces] signal using [http/protobuf]. Install ext-protobuf or select [http/json].
trace_json_override=success
invalid_compression=InvalidArgumentException:Unsupported OTLP [traces] compression [brotli]. Use [none] or [gzip].
OUTPUT . PHP_EOL, $process->getOutput(), $diagnostic);
    }

    public function testInvalidMetricTemporalityFailsBeforeTransportCreation(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['temporality'] = 'invalid';

        try {
            $factory->metricExporter($config);
            $this->fail('An unsupported metric temporality was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unsupported metric temporality [invalid].', $exception->getMessage());
        }

        $this->assertSame([], $factory->createdOptions);
    }

    public function testRejectsIncompleteMutualTlsConfiguration(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['client_certificate'] = '/certificates/client.pem';

        try {
            $factory->validate($config);
            $this->fail('An incomplete mutual TLS configuration was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The OTLP [traces] client certificate and client key must be configured together.',
                $exception->getMessage(),
            );
        }
    }

    public function testRejectsUnreadableTlsFiles(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['certificate'] = '/missing/ca.pem';

        try {
            $factory->validate($config);
            $this->fail('An unreadable certificate was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The OTLP [traces] certificate file [/missing/ca.pem] is not readable.',
                $exception->getMessage(),
            );
        }
    }

    public function testRejectsUnsupportedCompressionAndInvalidNetworkBounds(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['compression'] = 'brotli';

        try {
            $factory->validate($config);
            $this->fail('Unsupported compression was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Unsupported OTLP [traces] compression [brotli]. Use [none] or [gzip].',
                $exception->getMessage(),
            );
        }

        $config = $this->config();
        $config['endpoint'] = '';

        try {
            $factory->validate($config);
            $this->fail('An empty endpoint was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The OTLP [traces] endpoint must not be empty.',
                $exception->getMessage(),
            );
        }

        $config = $this->config();
        $config['timeout'] = 0;

        try {
            $factory->validate($config);
            $this->fail('A non-positive timeout was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The OTLP [traces] timeout must be a positive integer.',
                $exception->getMessage(),
            );
        }

        $config = $this->config();
        $config['max_retries'] = -1;

        try {
            $factory->validate($config);
            $this->fail('A negative retry limit was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The OTLP retry limit must be a non-negative integer.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Return a complete shared OTLP configuration fixture.
     */
    private function config(): array
    {
        return [
            'endpoint' => 'http://collector.test:4318/base',
            'protocol' => Protocols::HTTP_JSON,
            'headers' => ['shared' => 'header'],
            'compression' => 'none',
            'timeout' => 10000,
            'certificate' => null,
            'client_certificate' => null,
            'client_key' => null,
            'traces_endpoint' => null,
            'traces_protocol' => null,
            'traces_headers' => null,
            'traces_compression' => null,
            'traces_timeout' => null,
            'traces_certificate' => null,
            'traces_client_certificate' => null,
            'traces_client_key' => null,
            'metrics_endpoint' => null,
            'metrics_protocol' => null,
            'metrics_headers' => null,
            'metrics_compression' => null,
            'metrics_timeout' => null,
            'metrics_certificate' => null,
            'metrics_client_certificate' => null,
            'metrics_client_key' => null,
            'logs_endpoint' => null,
            'logs_protocol' => null,
            'logs_headers' => null,
            'logs_compression' => null,
            'logs_timeout' => null,
            'logs_certificate' => null,
            'logs_client_certificate' => null,
            'logs_client_key' => null,
            'max_retries' => 3,
        ];
    }
}

class TestOtlpExporterFactory extends OtlpExporterFactory
{
    /** @var list<array> */
    public array $createdOptions = [];

    /**
     * Resolve per-signal settings for testing.
     */
    public function resolveSignalConfig(array $config, string $signal): array
    {
        return $this->signalConfig($config, $signal);
    }

    /**
     * Resolve the content type for testing.
     */
    public function resolveContentType(string $protocol): string
    {
        return $this->contentType($protocol, 'traces');
    }

    /**
     * Resolve a shared client for testing.
     */
    public function resolveClient(array $config): ClientInterface
    {
        return $this->client($config);
    }

    /**
     * Resolve client options for testing.
     */
    public function resolveClientOptions(array $config): array
    {
        return $this->clientOptions($config);
    }

    /**
     * Resolve metric temporality for testing.
     */
    public function resolveTemporality(string $temporality): ?string
    {
        return $this->temporality($temporality);
    }

    /**
     * Validate normalized network settings for testing.
     */
    public function validate(array $config): void
    {
        $this->validateSignalConfig($config, 'traces');
    }

    /**
     * Create a recording PSR-18 client.
     */
    protected function createClient(array $options): ClientInterface
    {
        $this->createdOptions[] = $options;

        return m::mock(ClientInterface::class);
    }
}
