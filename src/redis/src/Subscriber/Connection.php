<?php

declare(strict_types=1);

namespace Hypervel\Redis\Subscriber;

use Hypervel\Redis\Subscriber\Exceptions\ServerException;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;

class Connection
{
    /**
     * The subscriber stream.
     *
     * @var null|resource
     */
    protected mixed $stream = null;

    protected bool $closed = false;

    /**
     * Create a new Redis subscriber connection.
     */
    public function __construct(
        string $host = '',
        int $port = 6379,
        float $timeout = 5.0,
        ?string $scheme = null,
        array $context = [],
    ) {
        $endpoint = $this->endpoint($host, $port, $scheme, $context);
        $streamOptions = $context['stream'] ?? $context['ssl'] ?? $context;
        $streamContext = stream_context_create(
            $streamOptions === [] ? [] : ['ssl' => $streamOptions]
        );
        $errorCode = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            $endpoint,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $streamContext,
        );

        if ($stream === false) {
            throw new SocketException(sprintf(
                'Failed to connect to Redis subscriber endpoint [%s]: [%d] %s.',
                $endpoint,
                $errorCode,
                $errorMessage,
            ));
        }

        $this->stream = $stream;
    }

    /**
     * Send a complete command to Redis.
     */
    public function send(string $data): bool
    {
        $written = 0;
        $length = strlen($data);

        while ($written < $length) {
            $bytes = @fwrite($this->stream, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                throw new SocketException(
                    'Failed to send data to the Redis subscriber socket.'
                );
            }

            $written += $bytes;
        }

        return true;
    }

    /**
     * Receive and decode one RESP2 value.
     */
    public function receive(): mixed
    {
        $line = $this->readLine();
        $prefix = $line[0] ?? throw new SocketException(
            'Received an empty Redis response.'
        );
        $value = substr($line, 1);

        return match ($prefix) {
            '+' => $value,
            '-' => throw new ServerException($value),
            ':' => $this->parseInteger($value),
            '$' => $this->readBulk($this->parseInteger($value)),
            '*' => $this->readArray($this->parseInteger($value)),
            default => throw new SocketException(
                "Unsupported Redis response type [{$prefix}]."
            ),
        };
    }

    /**
     * Close the Redis subscriber connection.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $stream = $this->stream;
        $this->stream = null;

        if (is_resource($stream) && ! fclose($stream)) {
            throw new SocketException('Failed to close the Redis subscriber socket.');
        }
    }

    /**
     * Build the stream endpoint.
     */
    private function endpoint(
        string $host,
        int $port,
        ?string $scheme,
        array $context,
    ): string {
        if ($host === '') {
            throw new SocketException('Redis subscriber host must be a non-empty string.');
        }

        if (str_starts_with($host, '/')) {
            if ($scheme !== null && strcasecmp($scheme, 'unix') !== 0) {
                throw new SocketException(
                    'A Redis Unix socket path cannot use a non-Unix scheme.'
                );
            }

            return 'unix://' . $host;
        }

        if (str_starts_with(strtolower($host), 'unix://')) {
            if ($scheme !== null && strcasecmp($scheme, 'unix') !== 0) {
                throw new SocketException(
                    'A Redis Unix socket path cannot use a non-Unix scheme.'
                );
            }

            $path = substr($host, strlen('unix://'));

            if (! str_starts_with($path, '/')) {
                throw new SocketException(
                    'A Redis Unix socket endpoint must contain an absolute path.'
                );
            }

            return 'unix://' . $path;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // PhpRedis treats a non-empty stream context as TLS unless an endpoint scheme overrides it.
            $scheme ??= $context === [] ? 'tcp' : 'tls';

            if (! in_array(strtolower($scheme), ['tcp', 'tls'], true)) {
                throw new SocketException(
                    "Unsupported Redis subscriber scheme [{$scheme}]."
                );
            }

            return strtolower($scheme) . "://[{$host}]:{$port}";
        }

        $hostScheme = parse_url($host, PHP_URL_SCHEME);
        $endpointScheme = $scheme === null
            ? ($context === [] ? 'tcp' : 'tls')
            : strtolower($scheme);

        if (is_string($hostScheme)) {
            $hostScheme = strtolower($hostScheme);

            if (! in_array($hostScheme, ['tcp', 'tls'], true)) {
                throw new SocketException(
                    "Unsupported Redis subscriber scheme [{$hostScheme}]."
                );
            }

            if ($scheme !== null && strcasecmp($hostScheme, $scheme) !== 0) {
                throw new SocketException(
                    'The scheme configured in the Redis subscriber host must match the scheme option.'
                );
            }

            $endpointScheme = $hostScheme;
            $endpoint = $host;
        } else {
            if (str_contains($host, '://')) {
                throw new SocketException('The Redis subscriber endpoint is malformed.');
            }

            $endpoint = "{$endpointScheme}://{$host}:{$port}";
        }

        if (! in_array($endpointScheme, ['tcp', 'tls'], true)) {
            throw new SocketException(
                "Unsupported Redis subscriber scheme [{$endpointScheme}]."
            );
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts)
            || ! isset($parts['host'])
            || ! is_string($parts['host'])
            || $parts['host'] === '') {
            throw new SocketException('The Redis subscriber endpoint is malformed.');
        }

        if (str_contains($parts['host'], ':')
            && ! str_starts_with($parts['host'], '[')) {
            throw new SocketException(
                'Redis subscriber hosts containing a colon must use bracketed IPv6 addresses; pass the port separately.'
            );
        }

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $component) {
            if (array_key_exists($component, $parts)) {
                throw new SocketException(
                    'A Redis TCP or TLS subscriber endpoint cannot contain credentials, a path, a query, or a fragment.'
                );
            }
        }

        $endpointPort = $parts['port'] ?? $port;

        return "{$endpointScheme}://{$parts['host']}:{$endpointPort}";
    }

    /**
     * Read one complete RESP line.
     */
    private function readLine(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            throw new SocketException('Failed to read from the Redis subscriber socket.');
        }

        if (! str_ends_with($line, Constants::CRLF)) {
            throw new SocketException('Received an incomplete Redis response line.');
        }

        return substr($line, 0, -strlen(Constants::CRLF));
    }

    /**
     * Read an exact number of bytes.
     */
    private function readExact(int $length): string
    {
        $result = '';

        while (strlen($result) < $length) {
            $chunk = fread($this->stream, $length - strlen($result));

            if ($chunk === false || $chunk === '') {
                throw new SocketException(
                    'Failed to read the complete Redis response payload.'
                );
            }

            $result .= $chunk;
        }

        return $result;
    }

    /**
     * Parse an exact native integer.
     */
    private function parseInteger(string $value): int
    {
        if (preg_match('/^[+-]?\d+\z/', $value) !== 1) {
            throw new SocketException("Invalid Redis integer [{$value}].");
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new SocketException("Redis integer [{$value}] exceeds the native integer range.");
        }

        return (int) $value;
    }

    /**
     * Read a bulk string.
     */
    private function readBulk(int $length): ?string
    {
        if ($length === -1) {
            return null;
        }

        if ($length < -1 || $length > PHP_INT_MAX - 2) {
            throw new SocketException("Invalid Redis bulk string length [{$length}].");
        }

        $payload = $this->readExact($length + 2);

        if (substr($payload, -2) !== Constants::CRLF) {
            throw new SocketException(
                'Redis bulk string is missing its trailing CRLF.'
            );
        }

        return substr($payload, 0, $length);
    }

    /**
     * Read an array.
     */
    private function readArray(int $length): ?array
    {
        if ($length === -1) {
            return null;
        }

        if ($length < -1) {
            throw new SocketException("Invalid Redis array length [{$length}].");
        }

        $values = [];

        for ($index = 0; $index < $length; ++$index) {
            $values[] = $this->receive();
        }

        return $values;
    }
}
