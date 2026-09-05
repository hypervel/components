<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Engine\Http\ClientInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\HttpClientBusyException;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Exceptions\InvalidArgumentException;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketConnectException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Swoole\Coroutine\Http\Client as NativeHttpClient;
use Swoole\Coroutine\Http\Client\Exception as NativeHttpClientException;
use Throwable;

class Client implements ClientInterface
{
    private const DEFAULT_TRANSFER_SETTINGS = [
        'connect_timeout' => 0.0,
        'timeout' => 0.0,
        'read_timeout' => 0.0,
        'body_decompression' => true,
    ];

    private const FIXED_SETTINGS = [
        'keep_alive' => true,
        'http_compression' => false,
        'lowercase_header' => false,
    ];

    private const CONSTRUCTION_BOOLEAN_SETTINGS = [
        'ssl_verify_peer',
        'ssl_allow_self_signed',
    ];

    private const CONSTRUCTION_FILE_SETTINGS = [
        'ssl_cafile',
        'ssl_cert_file',
        'ssl_key_file',
    ];

    private const SUPPORTED_TLS_PROTOCOLS = SWOOLE_SSL_TLSv1
        | SWOOLE_SSL_TLSv1_1
        | SWOOLE_SSL_TLSv1_2
        | SWOOLE_SSL_TLSv1_3;

    private const LOST_CONNECTION_ERRORS = [
        SOCKET_EPIPE,
        SOCKET_ECONNRESET,
        SOCKET_ECONNABORTED,
        SOCKET_ENOTCONN,
    ];

    private NativeHttpClient $client;

    /** @var array<string, bool|int|string> */
    private array $constructionSettings;

    /** @var array{connect_timeout: float, timeout: float, read_timeout: float, body_decompression: bool} */
    private array $transferSettings = self::DEFAULT_TRANSFER_SETTINGS;

    private bool $configured = false;

    private bool $busy = false;

    /**
     * Create a new HTTP/1.1 client instance.
     *
     * @param array<string, mixed> $settings
     */
    public function __construct(
        string $host,
        int $port = 80,
        bool $ssl = false,
        array $settings = [],
    ) {
        if ($host === '') {
            throw new InvalidArgumentException('The HTTP client host must not be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The HTTP client port must be between 1 and 65535.');
        }

        $this->constructionSettings = $this->validateConstructionSettings($settings);

        try {
            $this->client = $this->createNativeClient($host, $port, $ssl);
        } catch (NativeHttpClientException $exception) {
            throw new HttpClientException(
                $exception->getMessage(),
                $exception->getCode(),
                $exception,
            );
        }
    }

    /**
     * Configure the next and subsequent transfers.
     *
     * @param array<string, mixed> $settings
     */
    public function set(array $settings): void
    {
        if ($this->busy) {
            throw new HttpClientBusyException('The HTTP client cannot be configured while a response is pending.');
        }

        $this->transferSettings = array_replace(
            $this->transferSettings,
            $this->validateTransferSettings($settings),
        );
    }

    /**
     * Send an HTTP request and receive its response.
     *
     * @param array<string, string|string[]> $headers
     */
    public function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): RawResponse {
        $this->send($method, $path, $headers, $contents, $version);

        return $this->recv();
    }

    /**
     * Send an HTTP request without receiving its response.
     *
     * @param array<string, string|string[]> $headers
     */
    public function send(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): void {
        if ($version !== '1.1') {
            throw new InvalidArgumentException('The HTTP client only supports HTTP/1.1 requests.');
        }

        if ($this->busy) {
            throw new HttpClientBusyException('The HTTP client already has a pending response.');
        }

        $this->ensureCoroutine();
        $this->busy = true;

        try {
            if ($this->client->setDefer(true) === false) {
                $this->throwConfigurationException('Unable to defer the HTTP response.');
            }

            if ($this->client->setHeaders($this->encodeHeaders($headers)) === false) {
                $this->throwConfigurationException('Unable to configure the HTTP request headers.');
            }

            $this->client->cookies = null;
            $this->applySettings();

            if ($this->client->setMethod($method) === false) {
                $this->throwConfigurationException('Unable to configure the HTTP request method.');
            }

            if ($this->client->setData($contents) === false) {
                $this->throwConfigurationException('Unable to configure the HTTP request body.');
            }
        } catch (NativeHttpClientException $exception) {
            $this->throwConfigurationException('Unable to configure the HTTP request.', $exception);
        }

        try {
            $executed = $this->client->execute($path);
        } catch (NativeHttpClientException $exception) {
            $this->throwTransportException('Unable to send the HTTP request.', $exception);
        }

        if ($executed === false) {
            $this->throwTransportException('Unable to send the HTTP request.');
        }
    }

    /**
     * Receive the pending HTTP response.
     */
    public function recv(float $timeout = 0): RawResponse
    {
        if (! is_finite($timeout) || $timeout < 0) {
            throw new InvalidArgumentException(
                'The HTTP receive timeout must be a non-negative finite number of seconds.',
            );
        }

        if (! $this->busy) {
            throw new HttpClientException('The HTTP client has no pending response.');
        }

        $this->ensureCoroutine();

        try {
            $received = $this->client->recv($timeout);
        } catch (NativeHttpClientException $exception) {
            $this->throwTransportException('Unable to receive the HTTP response.', $exception);
        }

        if ($received === false) {
            $this->throwTransportException('Unable to receive the HTTP response.');
        }

        $this->busy = false;

        return new RawResponse(
            $this->client->statusCode,
            $this->decodeHeaders($this->client->headers ?? []),
            $this->client->body,
            '1.1',
        );
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        $this->busy = false;

        if (! $this->client->connected) {
            return;
        }

        $this->ensureCoroutine();

        try {
            $closed = $this->client->close();
        } catch (NativeHttpClientException $exception) {
            throw $this->createHttpClientException('Unable to close the HTTP client.', $exception);
        }

        if ($closed === false) {
            throw $this->createHttpClientException('Unable to close the HTTP client.');
        }
    }

    /**
     * Determine if the client is connected.
     */
    public function isConnected(): bool
    {
        return $this->client->connected;
    }

    /**
     * Destroy the HTTP client.
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (Throwable) {
            // Destructors cannot report cleanup failures safely.
        }
    }

    /**
     * Create the native HTTP client.
     */
    protected function createNativeClient(string $host, int $port, bool $ssl): NativeHttpClient
    {
        return new NativeHttpClient($host, $port, $ssl);
    }

    /**
     * Apply the complete native settings for the next transfer.
     */
    private function applySettings(): void
    {
        $settings = array_merge(
            $this->configured ? [] : $this->constructionSettings,
            self::FIXED_SETTINGS,
            $this->transferSettings,
        );

        try {
            $configured = $this->client->set($settings);
        } catch (NativeHttpClientException $exception) {
            $this->throwConfigurationException('Unable to configure the HTTP client.', $exception);
        }

        if ($configured === false) {
            $this->throwConfigurationException('Unable to configure the HTTP client.');
        }

        $this->configured = true;
    }

    /**
     * Validate construction-only settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, bool|int|string>
     */
    private function validateConstructionSettings(array $settings): array
    {
        $validated = [];

        foreach ($settings as $name => $value) {
            if (in_array($name, self::CONSTRUCTION_BOOLEAN_SETTINGS, true)) {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException("The HTTP client setting [{$name}] must be a boolean.");
                }

                $validated[$name] = $value;

                continue;
            }

            if (in_array($name, self::CONSTRUCTION_FILE_SETTINGS, true)) {
                if (! is_string($value) || $value === '' || ! is_file($value) || ! is_readable($value)) {
                    throw new InvalidArgumentException(
                        "The HTTP client setting [{$name}] must be a non-empty path to a readable file.",
                    );
                }

                $validated[$name] = $value;

                continue;
            }

            if ($name === 'ssl_capath') {
                if (! is_string($value) || $value === '' || ! is_dir($value) || ! is_readable($value)) {
                    throw new InvalidArgumentException(
                        'The HTTP client setting [ssl_capath] must be a non-empty path to a readable directory.',
                    );
                }

                $validated[$name] = $value;

                continue;
            }

            if ($name === 'ssl_host_name') {
                if (! is_string($value) || $value === '') {
                    throw new InvalidArgumentException(
                        'The HTTP client setting [ssl_host_name] must be a non-empty string.',
                    );
                }

                $validated[$name] = $value;

                continue;
            }

            if ($name === 'ssl_protocols') {
                if (! is_int($value)
                    || $value === 0
                    || ($value & ~self::SUPPORTED_TLS_PROTOCOLS) !== 0) {
                    throw new InvalidArgumentException(
                        'The HTTP client setting [ssl_protocols] must be a non-zero combination of supported TLS protocol bits.',
                    );
                }

                $validated[$name] = $value;

                continue;
            }

            if (array_key_exists($name, self::DEFAULT_TRANSFER_SETTINGS)) {
                throw new InvalidArgumentException(
                    "The HTTP client setting [{$name}] must be configured through set().",
                );
            }

            throw new InvalidArgumentException("The HTTP client setting [{$name}] is not supported.");
        }

        if (array_key_exists('ssl_cert_file', $validated) !== array_key_exists('ssl_key_file', $validated)) {
            throw new InvalidArgumentException(
                'The HTTP client settings [ssl_cert_file] and [ssl_key_file] must be configured together.',
            );
        }

        return $validated;
    }

    /**
     * Validate per-transfer settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, bool|float>
     */
    private function validateTransferSettings(array $settings): array
    {
        $validated = [];

        foreach ($settings as $name => $value) {
            if (array_key_exists($name, self::FIXED_SETTINGS)) {
                throw new InvalidArgumentException("The HTTP client setting [{$name}] is managed by the engine.");
            }

            if (in_array($name, self::CONSTRUCTION_BOOLEAN_SETTINGS, true)
                || in_array($name, self::CONSTRUCTION_FILE_SETTINGS, true)
                || $name === 'ssl_capath'
                || $name === 'ssl_host_name'
                || $name === 'ssl_protocols') {
                throw new InvalidArgumentException(
                    "The HTTP client setting [{$name}] may only be configured during construction.",
                );
            }

            if ($name === 'body_decompression') {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException(
                        'The HTTP client setting [body_decompression] must be a boolean.',
                    );
                }

                $validated[$name] = $value;

                continue;
            }

            if (in_array($name, ['connect_timeout', 'timeout', 'read_timeout'], true)) {
                if (! is_int($value) && ! is_float($value)) {
                    throw new InvalidArgumentException(
                        "The HTTP client setting [{$name}] must be a non-negative finite number of seconds.",
                    );
                }

                $value = (float) $value;

                if (! is_finite($value) || $value < 0) {
                    throw new InvalidArgumentException(
                        "The HTTP client setting [{$name}] must be a non-negative finite number of seconds.",
                    );
                }

                $validated[$name] = $value;

                continue;
            }

            throw new InvalidArgumentException("The HTTP client setting [{$name}] is not supported.");
        }

        return $validated;
    }

    /**
     * Decode headers from Swoole format to standard format.
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, string[]>
     */
    private function decodeHeaders(array $headers): array
    {
        $decoded = [];

        foreach ($headers as $name => $value) {
            $decoded[$name] = is_array($value) ? $value : [$value];
        }

        return $decoded;
    }

    /**
     * Encode headers for Swoole.
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, string>
     */
    private function encodeHeaders(array $headers): array
    {
        $encoded = [];

        foreach ($headers as $name => $value) {
            $encoded[$name] = is_array($value) ? implode(', ', $value) : $value;
        }

        return $encoded;
    }

    /**
     * Ensure native client operations run inside a coroutine.
     */
    private function ensureCoroutine(): void
    {
        if (Coroutine::id() < 0) {
            throw new RunningInNonCoroutineException(
                'HTTP client operations must run inside a coroutine.',
            );
        }
    }

    /**
     * Throw a normalized configuration exception.
     */
    private function throwConfigurationException(
        string $fallbackMessage,
        ?NativeHttpClientException $previous = null,
    ): never {
        $exception = $this->createHttpClientException($fallbackMessage, $previous);
        $this->discard();

        throw $exception;
    }

    /**
     * Throw the most precise normalized transport exception.
     */
    private function throwTransportException(
        string $fallbackMessage,
        ?NativeHttpClientException $previous = null,
    ): never {
        $statusCode = $this->client->statusCode;
        $errorCode = $this->client->errCode ?: $previous?->getCode() ?: 0;
        $message = $this->client->errMsg ?: $previous?->getMessage() ?: $fallbackMessage;

        $this->discard();

        $exceptionClass = match (true) {
            $statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED => SocketConnectException::class,
            $statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT,
            $errorCode === SOCKET_ETIMEDOUT => SocketTimeoutException::class,
            $statusCode === SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET,
            in_array($errorCode, self::LOST_CONNECTION_ERRORS, true) => SocketClosedException::class,
            default => HttpClientException::class,
        };

        throw new $exceptionClass($message, $errorCode, $previous);
    }

    /**
     * Create a normalized HTTP client exception.
     */
    private function createHttpClientException(
        string $fallbackMessage,
        ?NativeHttpClientException $previous = null,
    ): HttpClientException {
        return new HttpClientException(
            $this->client->errMsg ?: $previous?->getMessage() ?: $fallbackMessage,
            $this->client->errCode ?: $previous?->getCode() ?: 0,
            $previous,
        );
    }

    /**
     * Close a failed client without replacing the transport failure.
     */
    private function discard(): void
    {
        $this->busy = false;

        if (! $this->client->connected || Coroutine::id() < 0) {
            return;
        }

        try {
            $this->client->close();
        } catch (Throwable) {
            // Preserve the operation failure that made this connection unsafe.
        }
    }
}
