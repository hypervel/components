<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Fixtures;

use Hypervel\Engine\Channel;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\go;

class RespServer
{
    /**
     * The listening stream.
     *
     * @var null|resource
     */
    private mixed $server = null;

    private Channel $completed;

    private ?Throwable $failure = null;

    /**
     * Create a new RESP test server.
     */
    public function __construct(
        private string $uri = 'tcp://127.0.0.1:0',
        array $context = [],
    ) {
        $errorCode = 0;
        $errorMessage = '';
        $streamContext = stream_context_create($context);
        $server = stream_socket_server(
            $uri,
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $streamContext,
        );

        if ($server === false) {
            throw new RuntimeException(sprintf(
                'Failed to create RESP test server [%s]: [%d] %s.',
                $uri,
                $errorCode,
                $errorMessage,
            ));
        }

        $this->server = $server;
        $this->completed = new Channel(1);
    }

    /**
     * Get the connectable server endpoint.
     */
    public function endpoint(): string
    {
        if (str_starts_with($this->uri, 'unix://')) {
            return $this->uri;
        }

        $scheme = parse_url($this->uri, PHP_URL_SCHEME) ?: 'tcp';
        $address = stream_socket_get_name($this->server, false);

        if ($address === false) {
            throw new RuntimeException('Failed to resolve the RESP test server address.');
        }

        return "{$scheme}://{$address}";
    }

    /**
     * Get the server host and port.
     *
     * @return array{0: string, 1: int}
     */
    public function hostAndPort(): array
    {
        $endpoint = $this->endpoint();
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);

        if (! is_string($host) || ! is_int($port)) {
            throw new RuntimeException("Invalid RESP test endpoint [{$endpoint}].");
        }

        return [$host, $port];
    }

    /**
     * Read an exact number of bytes from a test stream.
     *
     * @param resource $stream
     */
    public static function readExact(mixed $stream, int $length): string
    {
        $value = '';

        while (strlen($value) < $length) {
            $chunk = fread($stream, $length - strlen($value));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed to read the complete test command.');
            }

            $value .= $chunk;
        }

        return $value;
    }

    /**
     * Handle one client connection in a coroutine.
     *
     * @param callable(resource): void $handler
     */
    public function start(callable $handler): void
    {
        go(function () use ($handler): void {
            $client = null;

            try {
                $client = stream_socket_accept($this->server, 2.0);

                if ($client === false) {
                    throw new RuntimeException('Timed out accepting a RESP test client.');
                }

                $handler($client);
            } catch (Throwable $exception) {
                $this->failure = $exception;
            } finally {
                if (is_resource($client)) {
                    fclose($client);
                }

                $this->completed->push(true);
            }
        });
    }

    /**
     * Wait for the server handler to finish and close the listener.
     */
    public function wait(float $timeout = 2.0): void
    {
        try {
            if (! $this->completed->pop($timeout)) {
                throw new RuntimeException('Timed out waiting for the RESP test server.');
            }

            if ($this->failure !== null) {
                throw $this->failure;
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Close the listening stream.
     */
    public function close(): void
    {
        $server = $this->server;
        $this->server = null;

        if (is_resource($server)) {
            fclose($server);
        }
    }
}
