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
use Psr\Http\Client\ClientInterface;

class OtlpExporterFactoryTest extends TestCase
{
    public function testSignalOverridesTakePrecedenceAndSharedEndpointsReceiveTheStandardPath(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();

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
                'Unsupported OTLP protocol [grpc]. Use [http/protobuf] or [http/json].',
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client certificate and client key must be configured together');

        $factory->validate($config);
    }

    public function testRejectsUnreadableTlsFiles(): void
    {
        $factory = new TestOtlpExporterFactory;
        $config = $this->config();
        $config['certificate'] = '/missing/ca.pem';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('certificate file [/missing/ca.pem] is not readable');

        $factory->validate($config);
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
            $this->assertStringContainsString('Unsupported OTLP compression [brotli]', $exception->getMessage());
        }

        $config = $this->config();
        $config['timeout'] = 0;

        try {
            $factory->validate($config);
            $this->fail('A non-positive timeout was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('timeout must be a positive integer', $exception->getMessage());
        }

        $config = $this->config();
        $config['max_retries'] = -1;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry limit must be a non-negative integer');

        $factory->validate($config);
    }

    /**
     * Return a complete shared OTLP configuration fixture.
     */
    private function config(): array
    {
        return [
            'endpoint' => 'http://collector.test:4318/base',
            'protocol' => Protocols::HTTP_PROTOBUF,
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
        return $this->contentType($protocol);
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
        $this->validateSignalConfig($config);
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
