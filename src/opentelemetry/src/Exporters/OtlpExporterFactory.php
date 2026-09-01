<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Exporters;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use InvalidArgumentException;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Adapter\HttpDiscovery\MessageFactoryResolver;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Create OTLP/HTTP exporters with coroutine-compatible Guzzle transports.
 */
class OtlpExporterFactory implements ExporterFactory
{
    protected readonly HttpFactory $httpFactory;

    protected readonly HttpEndpointResolver $endpointResolver;

    /** @var array<string, ClientInterface> */
    protected array $clients = [];

    /**
     * Create an OTLP exporter factory.
     */
    public function __construct()
    {
        $this->httpFactory = new HttpFactory;
        $this->endpointResolver = new HttpEndpointResolver(
            MessageFactoryResolver::create(uriFactory: $this->httpFactory),
        );
    }

    /**
     * Create an OTLP span exporter.
     */
    public function spanExporter(array $config): SpanExporterInterface
    {
        return new SpanExporter($this->transport($config, Signals::TRACE));
    }

    /**
     * Create an OTLP metric exporter.
     */
    public function metricExporter(array $config): MetricExporterInterface
    {
        $temporality = $this->temporality($config['temporality']);

        return new MetricExporter(
            $this->transport($config, Signals::METRICS),
            $temporality,
        );
    }

    /**
     * Create an OTLP log-record exporter.
     */
    public function logExporter(array $config): LogRecordExporterInterface
    {
        return new LogsExporter($this->transport($config, Signals::LOGS));
    }

    /**
     * Create an OTLP transport for one signal.
     */
    protected function transport(array $config, string $signal): TransportInterface
    {
        $signalPrefix = $this->signalPrefix($signal);
        $config = $this->signalConfig($config, $signal);
        $this->validateSignalConfig($config, $signalPrefix);
        $client = $this->client($config);

        return (new PsrTransportFactory($client, $this->httpFactory, $this->httpFactory))->create(
            $config['endpoint'],
            $this->contentType($config['protocol'], $signalPrefix),
            $config['headers'],
            $config['compression'] === 'none' ? null : $config['compression'],
            $config['timeout'] / 1000,
            maxRetries: $config['max_retries'],
        );
    }

    /**
     * Validate final signal settings before constructing an exporter transport.
     *
     * @param array{
     *     endpoint: string,
     *     protocol: string,
     *     headers: array,
     *     compression: string,
     *     timeout: int,
     *     certificate: ?string,
     *     client_certificate: ?string,
     *     client_key: ?string,
     *     max_retries: int
     * } $config
     */
    protected function validateSignalConfig(array $config, string $signalPrefix): void
    {
        if ($config['endpoint'] === '') {
            throw new InvalidArgumentException("The OTLP [{$signalPrefix}] endpoint must not be empty.");
        }

        $this->contentType($config['protocol'], $signalPrefix);

        if (! in_array($config['compression'], ['none', 'gzip'], true)) {
            throw new InvalidArgumentException(
                "Unsupported OTLP [{$signalPrefix}] compression [{$config['compression']}]. Use [none] or [gzip].",
            );
        }

        if ($config['timeout'] <= 0) {
            throw new InvalidArgumentException("The OTLP [{$signalPrefix}] timeout must be a positive integer.");
        }

        if ($config['max_retries'] < 0) {
            throw new InvalidArgumentException('The OTLP retry limit must be a non-negative integer.');
        }

        if (($config['client_certificate'] === null) !== ($config['client_key'] === null)) {
            throw new InvalidArgumentException(
                "The OTLP [{$signalPrefix}] client certificate and client key must be configured together.",
            );
        }

        foreach (['certificate', 'client_certificate', 'client_key'] as $option) {
            $path = $config[$option];

            if ($path !== null && ! is_readable($path)) {
                throw new InvalidArgumentException("The OTLP [{$signalPrefix}] {$option} file [{$path}] is not readable.");
            }
        }

        if ($config['protocol'] === Protocols::HTTP_PROTOBUF && ! extension_loaded('protobuf')) {
            throw new RuntimeException(
                "The \"protobuf\" PHP extension is required to export the OTLP [{$signalPrefix}] signal using [http/protobuf]. Install ext-protobuf or select [http/json].",
            );
        }
    }

    /**
     * Return the configuration prefix for an OpenTelemetry signal.
     */
    protected function signalPrefix(string $signal): string
    {
        return match ($signal) {
            Signals::TRACE => 'traces',
            Signals::METRICS => 'metrics',
            Signals::LOGS => 'logs',
            default => throw new InvalidArgumentException("Unsupported OpenTelemetry signal [{$signal}]."),
        };
    }

    /**
     * Resolve the final per-signal OTLP settings.
     *
     * @return array{
     *     endpoint: string,
     *     protocol: string,
     *     headers: array,
     *     compression: string,
     *     timeout: int,
     *     certificate: ?string,
     *     client_certificate: ?string,
     *     client_key: ?string,
     *     max_retries: int
     * }
     */
    protected function signalConfig(array $config, string $signal): array
    {
        $prefix = $this->signalPrefix($signal);
        $endpoint = $config["{$prefix}_endpoint"] ?? null;

        return [
            'endpoint' => $endpoint ?? $this->endpointResolver->resolveToString($config['endpoint'], $signal),
            'protocol' => $config["{$prefix}_protocol"] ?? $config['protocol'],
            'headers' => $config["{$prefix}_headers"] ?? $config['headers'],
            'compression' => $config["{$prefix}_compression"] ?? $config['compression'],
            'timeout' => $config["{$prefix}_timeout"] ?? $config['timeout'],
            'certificate' => $config["{$prefix}_certificate"] ?? $config['certificate'],
            'client_certificate' => $config["{$prefix}_client_certificate"] ?? $config['client_certificate'],
            'client_key' => $config["{$prefix}_client_key"] ?? $config['client_key'],
            'max_retries' => $config['max_retries'],
        ];
    }

    /**
     * Return the OTLP content type for an HTTP protocol.
     */
    protected function contentType(string $protocol, string $signalPrefix): string
    {
        return match ($protocol) {
            Protocols::HTTP_PROTOBUF => ContentTypes::PROTOBUF,
            Protocols::HTTP_JSON => ContentTypes::JSON,
            default => throw new InvalidArgumentException(
                "Unsupported OTLP [{$signalPrefix}] protocol [{$protocol}]. Use [http/protobuf] or [http/json].",
            ),
        };
    }

    /**
     * Return a client shared by signals with the same network settings.
     *
     * @param array{
     *     timeout: int,
     *     certificate: ?string,
     *     client_certificate: ?string,
     *     client_key: ?string
     * } $config
     */
    protected function client(array $config): ClientInterface
    {
        $key = hash('xxh128', serialize([
            $config['timeout'],
            $config['certificate'],
            $config['client_certificate'],
            $config['client_key'],
        ]));

        return $this->clients[$key] ??= $this->createClient($this->clientOptions($config));
    }

    /**
     * Create the PSR-18 client used by an OTLP transport.
     */
    protected function createClient(array $options): ClientInterface
    {
        return new Client($options);
    }

    /**
     * Build Guzzle options for the normalized TLS and timeout settings.
     *
     * @param array{
     *     timeout: int,
     *     certificate: ?string,
     *     client_certificate: ?string,
     *     client_key: ?string
     * } $config
     */
    protected function clientOptions(array $config): array
    {
        $options = [
            'timeout' => $config['timeout'] / 1000,
            'verify' => $config['certificate'] ?? true,
        ];

        if ($config['client_certificate'] !== null) {
            $options['cert'] = $config['client_certificate'];
            $options['ssl_key'] = $config['client_key'];
        }

        return $options;
    }

    /**
     * Map the configured metric temporality to the SDK representation.
     */
    protected function temporality(string $temporality): ?string
    {
        return match (strtolower($temporality)) {
            'cumulative' => Temporality::CUMULATIVE,
            'delta' => Temporality::DELTA,
            'lowmemory' => null,
            default => throw new InvalidArgumentException(
                "Unsupported metric temporality [{$temporality}].",
            ),
        };
    }
}
