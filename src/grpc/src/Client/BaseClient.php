<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Closure;
use Composer\InstalledVersions;
use Google\Protobuf\Internal\Message;
use Hypervel\Container\Container;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MediaType;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Protocol\MetadataCodec;
use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Grpc\StatusCode;
use InvalidArgumentException;
use LogicException;
use Throwable;

abstract class BaseClient
{
    private const float DEFAULT_WRITE_TIMEOUT = 60.0;

    private const string RESERVED_TIMEOUT_HEADER = '99999999n';

    private const array OPTION_KEYS = [
        'connections',
        'connect_timeout',
        'timeout',
        'max_receive_message_size',
        'max_send_message_size',
        'max_metadata_size',
        'max_buffered_messages',
        'max_buffered_bytes',
        'compression',
        'retry',
        'metadata',
        'tls',
        'swoole',
    ];

    private const array CALL_OPTION_KEYS = ['timeout', 'compression', 'retry'];

    private const array TLS_OPTION_KEYS = [
        'enabled',
        'verify_peer',
        'ca_file',
        'certificate',
        'private_key',
        'passphrase',
        'server_name',
    ];

    private const array TLS_ONLY_OPTION_KEYS = [
        'verify_peer',
        'ca_file',
        'certificate',
        'private_key',
        'passphrase',
        'server_name',
    ];

    private Endpoint $endpoint;

    private ClientFactoryInterface $clientFactory;

    private Metadata $defaultMetadata;

    private ?float $defaultTimeout;

    private Compression $defaultCompression;

    private ?RetryPolicy $defaultRetryPolicy;

    private int $maxReceiveMessageSize;

    private int $maxMetadataSize;

    private int $maxBufferedMessages;

    private int $maxBufferedBytes;

    private float $connectTimeout;

    private float $writeTimeout;

    private FrameEncoder $requestEncoder;

    private readonly string $userAgent;

    /** @var array<string, mixed> */
    private array $connectionSettings;

    /** @var list<Connection> */
    private array $connections = [];

    /** @var array<int, Connection> */
    private array $retiringConnections = [];

    private int $nextConnectionIndex = 0;

    private bool $closed = false;

    /**
     * Create a reusable generated-style gRPC client.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(private readonly string $target, array $options = [])
    {
        $this->assertKnownOptions($options, self::OPTION_KEYS, 'client');

        $connectionCount = $this->positiveIntegerOption($options, 'connections', 1);
        $this->connectTimeout = $this->positiveSecondsOption($options, 'connect_timeout', 3.0);
        $this->defaultTimeout = $this->nullableSecondsOption($options, 'timeout', null);
        $this->maxReceiveMessageSize = $this->messageSizeOption(
            $options,
            'max_receive_message_size',
            4 * 1024 * 1024,
        );
        $maxSendMessageSize = $this->messageSizeOption(
            $options,
            'max_send_message_size',
            4 * 1024 * 1024,
        );
        $this->maxMetadataSize = $this->positiveIntegerOption(
            $options,
            'max_metadata_size',
            8 * 1024,
        );
        $this->maxBufferedMessages = $this->positiveIntegerOption(
            $options,
            'max_buffered_messages',
            128,
        );
        $this->maxBufferedBytes = $this->positiveIntegerOption(
            $options,
            'max_buffered_bytes',
            16 * 1024 * 1024,
        );

        if ($this->maxBufferedBytes < $this->maxReceiveMessageSize) {
            throw new InvalidArgumentException(
                'The buffered gRPC byte limit cannot be smaller than the receive message limit.',
            );
        }

        $this->defaultCompression = $this->compressionOption($options, 'compression');
        $this->defaultRetryPolicy = $this->retryOption($options, 'retry');
        $this->defaultMetadata = $this->metadataOption($options, 'metadata');

        [$tls, $suppliedTlsKeys] = $this->normalizeTlsOptions($options);
        $this->endpoint = Endpoint::parse($target, $tls['enabled']);

        if (! $this->endpoint->tls) {
            $unusableKeys = array_values(array_intersect($suppliedTlsKeys, self::TLS_ONLY_OPTION_KEYS));

            if ($unusableKeys !== []) {
                throw new InvalidArgumentException(
                    'TLS-only gRPC client options cannot be used with a plaintext target: '
                    . implode(', ', $unusableKeys) . '.',
                );
            }
        }

        $swooleSettings = $this->swooleSettings($options);
        $this->writeTimeout = $this->writeTimeout($swooleSettings);
        unset($swooleSettings['write_timeout']);
        $tlsSettings = $this->endpoint->tls
            ? [
                'ssl_verify_peer' => $tls['verify_peer'],
                'ssl_cafile' => $tls['ca_file'],
                'ssl_cert_file' => $tls['certificate'],
                'ssl_key_file' => $tls['private_key'],
                'ssl_passphrase' => $tls['passphrase'],
                'ssl_host_name' => $tls['server_name'] ?? $this->endpoint->host,
            ]
            : [];

        $this->connectionSettings = array_replace(
            array_filter($tlsSettings, static fn (mixed $value): bool => $value !== null),
            $swooleSettings,
        );
        $this->requestEncoder = new FrameEncoder($maxSendMessageSize);
        $this->clientFactory = Container::getInstance()->make(ClientFactoryInterface::class);
        $version = InstalledVersions::isInstalled('hypervel/grpc')
            ? InstalledVersions::getPrettyVersion('hypervel/grpc')
            : null;
        $this->userAgent = 'grpc-php-hypervel/' . ($version ?? 'unknown')
            . ' (PHP/' . PHP_VERSION . '; Swoole/' . SWOOLE_VERSION . ')';

        for ($index = 0; $index < $connectionCount; ++$index) {
            $this->connections[] = $this->newConnection();
        }
    }

    /**
     * Return the configured endpoint target.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * Close every connection owned by this client.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $connections = [...$this->connections, ...array_values($this->retiringConnections)];
        $this->connections = [];
        $this->retiringConnections = [];
        $failure = null;

        foreach ($connections as $connection) {
            try {
                $connection->close();
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Start a unary request.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    protected function _simpleRequest(
        string $method,
        Message $argument,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        MessageSerializer::validate($deserialize);
        $serviceMethod = ServiceMethod::parse($method);
        [$timeout, $compression, $retryPolicy] = $this->normalizeCallOptions($options, true);
        $deadline = Deadline::fromTimeout($timeout);
        $body = $this->requestEncoder->encode(
            MessageSerializer::serialize($argument),
            $compression,
        );
        $metadata = $this->prepareMetadata($metadata);
        [$state] = $this->startInitialAttempt(
            $serviceMethod->path(),
            $body,
            $metadata,
            $compression,
            $deadline,
            false,
        );

        return new UnaryCall(
            $state,
            $serviceMethod->path(),
            $this->endpoint->peer,
            $deserialize,
            $deadline,
            $retryPolicy,
            $retryPolicy === null ? null : $this->attemptFactory(
                $serviceMethod->path(),
                $body,
                $metadata,
                $compression,
                $deadline,
            ),
        );
    }

    /**
     * Start a client-streaming request.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    protected function _clientStreamRequest(
        string $method,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): ClientStreamingCall {
        MessageSerializer::validate($deserialize);
        $serviceMethod = ServiceMethod::parse($method);
        [$timeout, $compression] = $this->normalizeCallOptions($options, false);
        $deadline = Deadline::fromTimeout($timeout);
        $metadata = $this->prepareMetadata($metadata);
        [$state, $connection] = $this->startInitialAttempt(
            $serviceMethod->path(),
            '',
            $metadata,
            $compression,
            $deadline,
            true,
        );

        return new ClientStreamingCall(
            $state,
            $serviceMethod->path(),
            $this->endpoint->peer,
            $deserialize,
            $deadline,
            $connection,
            $this->requestEncoder,
            $compression,
        );
    }

    /**
     * Start a server-streaming request.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    protected function _serverStreamRequest(
        string $method,
        Message $argument,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        MessageSerializer::validate($deserialize);
        $serviceMethod = ServiceMethod::parse($method);
        [$timeout, $compression, $retryPolicy] = $this->normalizeCallOptions($options, true);
        $deadline = Deadline::fromTimeout($timeout);
        $body = $this->requestEncoder->encode(
            MessageSerializer::serialize($argument),
            $compression,
        );
        $metadata = $this->prepareMetadata($metadata);
        [$state] = $this->startInitialAttempt(
            $serviceMethod->path(),
            $body,
            $metadata,
            $compression,
            $deadline,
            false,
        );

        return new ServerStreamingCall(
            $state,
            $serviceMethod->path(),
            $this->endpoint->peer,
            $deserialize,
            $deadline,
            $retryPolicy,
            $retryPolicy === null ? null : $this->attemptFactory(
                $serviceMethod->path(),
                $body,
                $metadata,
                $compression,
                $deadline,
            ),
        );
    }

    /**
     * Start a bidirectional-streaming request.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    protected function _bidiRequest(
        string $method,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): BidiStreamingCall {
        MessageSerializer::validate($deserialize);
        $serviceMethod = ServiceMethod::parse($method);
        [$timeout, $compression] = $this->normalizeCallOptions($options, false);
        $deadline = Deadline::fromTimeout($timeout);
        $metadata = $this->prepareMetadata($metadata);
        [$state, $connection] = $this->startInitialAttempt(
            $serviceMethod->path(),
            '',
            $metadata,
            $compression,
            $deadline,
            true,
        );

        return new BidiStreamingCall(
            $state,
            $serviceMethod->path(),
            $this->endpoint->peer,
            $deserialize,
            $deadline,
            $connection,
            $this->requestEncoder,
            $compression,
        );
    }

    /**
     * Prepare metadata for a new RPC.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     */
    protected function prepareMetadata(array|Metadata $metadata): Metadata
    {
        return $this->defaultMetadata->merge($metadata);
    }

    /**
     * Return the configured default metadata.
     */
    protected function defaultMetadata(): Metadata
    {
        return $this->defaultMetadata;
    }

    /**
     * Start the first transport attempt for a call.
     *
     * @return array{StreamState, Connection}
     */
    private function startInitialAttempt(
        string $path,
        string $body,
        Metadata $metadata,
        Compression $compression,
        Deadline $deadline,
        bool $pipeline,
    ): array {
        $this->ensureOpen();
        $state = $this->newStreamState($deadline);

        $requestFactory = $this->requestFactory(
            $path,
            $body,
            $metadata,
            $compression,
            $deadline,
            0,
            $pipeline,
        );

        $connection = $this->nextConnection();
        $connection->start($requestFactory, $state, $deadline);

        return [$state, $connection];
    }

    /**
     * Build the replay closure for an eligible call.
     *
     * @return Closure(int): StreamState
     */
    private function attemptFactory(
        string $path,
        string $body,
        Metadata $metadata,
        Compression $compression,
        Deadline $deadline,
    ): Closure {
        return function (int $previousAttempts) use (
            $path,
            $body,
            $metadata,
            $compression,
            $deadline,
        ): StreamState {
            $state = $this->newStreamState($deadline);

            try {
                $requestFactory = $this->requestFactory(
                    $path,
                    $body,
                    $metadata,
                    $compression,
                    $deadline,
                    $previousAttempts,
                    false,
                );
            } catch (RpcException $exception) {
                $state->failWithStatus($exception->status());

                return $state;
            }

            $this->nextConnection()->start($requestFactory, $state, $deadline);

            return $state;
        };
    }

    /**
     * Build one engine request and enforce its complete outbound metadata size.
     */
    private function requestFactory(
        string $path,
        string $body,
        Metadata $metadata,
        Compression $compression,
        Deadline $deadline,
        int $previousAttempts,
        bool $pipeline,
    ): Closure {
        $headers = [
            'content-type' => MediaType::PROTOBUF,
            'te' => 'trailers',
            'user-agent' => $this->userAgent,
            'grpc-accept-encoding' => 'identity,gzip',
            'host' => $this->endpoint->authority,
        ];

        if ($compression !== Compression::Identity) {
            $headers['grpc-encoding'] = $compression->value;
        }

        if ($deadline->absoluteNanoseconds() !== null) {
            $headers['grpc-timeout'] = self::RESERVED_TIMEOUT_HEADER;
        }

        if ($previousAttempts > 0) {
            $headers['grpc-previous-rpc-attempts'] = (string) $previousAttempts;
        }

        $headers = [...$headers, ...MetadataCodec::encode($metadata)];
        $accountedHeaders = $headers;
        unset($accountedHeaders['host']);
        $accountedHeaders = [
            ':method' => 'POST',
            ':scheme' => $this->endpoint->tls ? 'https' : 'http',
            ':path' => $path,
            ':authority' => $this->endpoint->authority,
            ...$accountedHeaders,
        ];

        if (MetadataCodec::wireSize($accountedHeaders) > $this->maxMetadataSize) {
            throw new RpcException(
                StatusCode::ResourceExhausted,
                'The outbound gRPC metadata exceeds the configured limit.',
            );
        }

        return static function () use (
            $path,
            $body,
            $headers,
            $pipeline,
            $deadline,
        ): Request {
            if ($deadline->absoluteNanoseconds() !== null) {
                $headers['grpc-timeout'] = $deadline->encodedHeader() ?? throw new RpcException(
                    StatusCode::DeadlineExceeded,
                    'The gRPC deadline was exceeded.',
                );
            }

            return new Request(
                $path,
                'POST',
                $body,
                $headers,
                $pipeline,
                true,
            );
        };
    }

    /**
     * Create one per-attempt response state.
     */
    private function newStreamState(Deadline $deadline): StreamState
    {
        return new StreamState(
            $deadline,
            $this->maxReceiveMessageSize,
            $this->maxMetadataSize,
            $this->maxBufferedMessages,
            $this->maxBufferedBytes,
        );
    }

    /**
     * Select the next accepting connection and replace a retiring slot.
     */
    private function nextConnection(): Connection
    {
        $this->ensureOpen();
        $connectionCount = count($this->connections);
        $index = $this->nextConnectionIndex % $connectionCount;
        ++$this->nextConnectionIndex;
        $connection = $this->connections[$index];

        if ($connection->isAccepting()) {
            return $connection;
        }

        if (! $connection->isClosed()) {
            $this->retiringConnections[spl_object_id($connection)] = $connection;
        }

        return $this->connections[$index] = $this->newConnection();
    }

    /**
     * Create one lazy connection slot.
     */
    private function newConnection(): Connection
    {
        return new Connection(
            $this->clientFactory,
            $this->endpoint,
            $this->connectTimeout,
            $this->writeTimeout,
            $this->connectionSettings,
            function (Connection $connection): void {
                unset($this->retiringConnections[spl_object_id($connection)]);
            },
        );
    }

    /**
     * Normalize per-call timeout, compression, and retry settings.
     *
     * @param array<string, mixed> $options
     * @return array{?float, Compression, ?RetryPolicy}
     */
    private function normalizeCallOptions(array $options, bool $retryable): array
    {
        $this->assertKnownOptions($options, self::CALL_OPTION_KEYS, 'call');

        if (! $retryable && array_key_exists('retry', $options)) {
            throw new InvalidArgumentException(
                'Client-streaming and bidirectional gRPC calls do not support retries.',
            );
        }

        $timeout = array_key_exists('timeout', $options)
            ? $this->nullableSecondsValue($options['timeout'], 'timeout')
            : $this->defaultTimeout;
        $compression = array_key_exists('compression', $options)
            ? $this->compressionValue($options['compression'], 'compression')
            : $this->defaultCompression;
        $retryPolicy = $retryable
            ? (array_key_exists('retry', $options)
                ? $this->retryValue($options['retry'], 'retry')
                : $this->defaultRetryPolicy)
            : null;

        return [$timeout, $compression, $retryPolicy];
    }

    /**
     * Normalize the nested TLS settings and retain explicitly supplied keys.
     *
     * @param array<string, mixed> $options
     * @return array{
     *     array{
     *         enabled: ?bool,
     *         verify_peer: bool,
     *         ca_file: ?string,
     *         certificate: ?string,
     *         private_key: ?string,
     *         passphrase: ?string,
     *         server_name: ?string
     *     },
     *     list<string>
     * }
     */
    private function normalizeTlsOptions(array $options): array
    {
        $rawTls = array_key_exists('tls', $options) ? $options['tls'] : [];

        if (! is_array($rawTls)) {
            throw new InvalidArgumentException('The gRPC TLS option must be an array.');
        }

        $this->assertKnownOptions($rawTls, self::TLS_OPTION_KEYS, 'TLS');
        $suppliedKeys = array_keys($rawTls);

        foreach ($suppliedKeys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('gRPC TLS option keys must be strings.');
            }
        }

        $enabled = $rawTls['enabled'] ?? null;
        $verifyPeer = array_key_exists('verify_peer', $rawTls)
            ? $rawTls['verify_peer']
            : true;

        if ($enabled !== null && ! is_bool($enabled)) {
            throw new InvalidArgumentException('The gRPC TLS enabled option must be a boolean or null.');
        }

        if (! is_bool($verifyPeer)) {
            throw new InvalidArgumentException('The gRPC TLS verify_peer option must be a boolean.');
        }

        $caFile = $this->nullableStringValue($rawTls['ca_file'] ?? null, 'tls.ca_file');
        $certificate = $this->nullableStringValue(
            $rawTls['certificate'] ?? null,
            'tls.certificate',
        );
        $privateKey = $this->nullableStringValue(
            $rawTls['private_key'] ?? null,
            'tls.private_key',
        );
        $passphrase = $this->nullableStringValue(
            $rawTls['passphrase'] ?? null,
            'tls.passphrase',
        );
        $serverName = $this->nullableStringValue(
            $rawTls['server_name'] ?? null,
            'tls.server_name',
        );

        if (($certificate === null) !== ($privateKey === null)) {
            throw new InvalidArgumentException(
                'The gRPC TLS certificate and private key must be supplied together.',
            );
        }

        if ($passphrase !== null && $privateKey === null) {
            throw new InvalidArgumentException(
                'The gRPC TLS passphrase requires a certificate and private key.',
            );
        }

        foreach ([
            'tls.ca_file' => $caFile,
            'tls.certificate' => $certificate,
            'tls.private_key' => $privateKey,
        ] as $name => $path) {
            if ($path !== null && (! is_file($path) || ! is_readable($path))) {
                throw new InvalidArgumentException("The gRPC {$name} file is not readable.");
            }
        }

        if ($serverName !== null && $serverName === '') {
            throw new InvalidArgumentException('The gRPC TLS server name cannot be empty.');
        }

        return [[
            'enabled' => $enabled,
            'verify_peer' => $verifyPeer,
            'ca_file' => $caFile,
            'certificate' => $certificate,
            'private_key' => $privateKey,
            'passphrase' => $passphrase,
            'server_name' => $serverName,
        ], $suppliedKeys];
    }

    /**
     * Normalize raw Swoole client settings.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function swooleSettings(array $options): array
    {
        $settings = array_key_exists('swoole', $options) ? $options['swoole'] : [];

        if (! is_array($settings)) {
            throw new InvalidArgumentException('The gRPC Swoole option must be an array.');
        }

        foreach (array_keys($settings) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('gRPC Swoole setting keys must be strings.');
            }
        }

        if (array_key_exists('connect_timeout', $settings)) {
            throw new InvalidArgumentException(
                'The gRPC Swoole connect_timeout setting is owned by the first-class connect_timeout option.',
            );
        }

        return $settings;
    }

    /**
     * Resolve the positive native socket-write timeout used between RPC deadlines.
     *
     * @param array<string, mixed> $settings
     */
    private function writeTimeout(array $settings): float
    {
        $key = array_key_exists('write_timeout', $settings)
            ? 'write_timeout'
            : (array_key_exists('timeout', $settings) ? 'timeout' : null);

        if ($key === null) {
            return self::DEFAULT_WRITE_TIMEOUT;
        }

        $value = $settings[$key];

        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0) {
            throw new InvalidArgumentException(
                "The gRPC Swoole {$key} setting must be a positive finite number of seconds.",
            );
        }

        return (float) $value;
    }

    /**
     * Reject options outside an exact supported key set.
     *
     * @param array<array-key, mixed> $options
     * @param list<string> $allowedKeys
     */
    private function assertKnownOptions(array $options, array $allowedKeys, string $scope): void
    {
        foreach (array_keys($options) as $key) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $name = is_string($key) ? $key : (string) $key;

                throw new InvalidArgumentException("The gRPC {$scope} option [{$name}] is not supported.");
            }
        }
    }

    /**
     * Return a positive integer option.
     *
     * @param array<string, mixed> $options
     */
    private function positiveIntegerOption(array $options, string $key, int $default): int
    {
        $value = array_key_exists($key, $options) ? $options[$key] : $default;

        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("The gRPC {$key} option must be a positive integer.");
        }

        return $value;
    }

    /**
     * Return a positive gRPC message-size option within the frame range.
     *
     * @param array<string, mixed> $options
     */
    private function messageSizeOption(array $options, string $key, int $default): int
    {
        $value = $this->positiveIntegerOption($options, $key, $default);

        if ($value > 0xFFFFFFFF) {
            throw new InvalidArgumentException(
                "The gRPC {$key} option cannot exceed the unsigned 32-bit frame limit.",
            );
        }

        return $value;
    }

    /**
     * Return a positive duration option in seconds.
     *
     * @param array<string, mixed> $options
     */
    private function positiveSecondsOption(array $options, string $key, float $default): float
    {
        $value = array_key_exists($key, $options) ? $options[$key] : $default;

        return $this->positiveSecondsValue($value, $key);
    }

    /**
     * Return a nullable duration option in seconds.
     *
     * @param array<string, mixed> $options
     */
    private function nullableSecondsOption(array $options, string $key, ?float $default): ?float
    {
        $value = array_key_exists($key, $options) ? $options[$key] : $default;

        return $this->nullableSecondsValue($value, $key);
    }

    /**
     * Normalize a positive duration value in seconds.
     */
    private function positiveSecondsValue(mixed $value, string $key): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0) {
            throw new InvalidArgumentException(
                "The gRPC {$key} option must be a positive finite number of seconds.",
            );
        }

        return (float) $value;
    }

    /**
     * Normalize a nullable duration value in seconds.
     */
    private function nullableSecondsValue(mixed $value, string $key): ?float
    {
        return $value === null ? null : $this->positiveSecondsValue($value, $key);
    }

    /**
     * Return a configured compression option.
     *
     * @param array<string, mixed> $options
     */
    private function compressionOption(array $options, string $key): Compression
    {
        $value = array_key_exists($key, $options) ? $options[$key] : null;

        return $this->compressionValue($value, $key);
    }

    /**
     * Normalize a compression value.
     */
    private function compressionValue(mixed $value, string $key): Compression
    {
        if ($value === null) {
            return Compression::Identity;
        }

        if ($value instanceof Compression) {
            return $value;
        }

        if (is_string($value) && ($compression = Compression::tryFrom($value)) !== null) {
            return $compression;
        }

        throw new InvalidArgumentException(
            "The gRPC {$key} option must be null, identity, gzip, or a Compression value.",
        );
    }

    /**
     * Return a configured retry policy.
     *
     * @param array<string, mixed> $options
     */
    private function retryOption(array $options, string $key): ?RetryPolicy
    {
        $value = array_key_exists($key, $options) ? $options[$key] : null;

        return $this->retryValue($value, $key);
    }

    /**
     * Normalize a retry-policy value.
     */
    private function retryValue(mixed $value, string $key): ?RetryPolicy
    {
        if ($value === null || $value instanceof RetryPolicy) {
            return $value;
        }

        throw new InvalidArgumentException("The gRPC {$key} option must be a RetryPolicy or null.");
    }

    /**
     * Return configured default metadata.
     *
     * @param array<string, mixed> $options
     */
    private function metadataOption(array $options, string $key): Metadata
    {
        $value = array_key_exists($key, $options) ? $options[$key] : [];

        if ($value instanceof Metadata) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The gRPC metadata option must be an array or Metadata value.');
        }

        /** @var array<string, list<string>|string> $value */
        return Metadata::make($value);
    }

    /**
     * Normalize a nullable string option.
     */
    private function nullableStringValue(mixed $value, string $key): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The gRPC {$key} option must be a string or null.");
        }

        return $value;
    }

    /**
     * Ensure this client remains available for new work.
     */
    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The gRPC client is closed and cannot start another call.');
        }
    }
}
