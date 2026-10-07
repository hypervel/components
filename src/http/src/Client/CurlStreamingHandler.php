<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use Closure;
use CurlHandle;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Handler\HostValidator;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\BufferStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\TransportSharing;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Sleep;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Runtime;
use Throwable;

/**
 * Deliver streaming responses without sharing an active cURL multi handle.
 *
 * @internal
 */
class CurlStreamingHandler
{
    public const int MAX_IDLE_CONNECTIONS = 3;

    protected CurlFactoryInterface $factory;

    /** @var list<CurlStreamingConnection> */
    protected array $idleConnections = [];

    /**
     * Create a streaming handler with optional named-connection retention.
     */
    public function __construct(array $options = [], protected int $maxIdleConnections = 0)
    {
        $sharing = CurlShareHandleState::fromOption($options['transport_sharing'] ?? null);
        $this->factory = new CurlFactory(
            $maxIdleConnections,
            $sharing->mode ?? TransportSharing::NONE,
            $sharing,
        );
    }

    /**
     * Add coroutine streaming while retaining the caller's fallback handler.
     */
    public static function wrap(callable $fallback, array $options = [], int $maxIdleConnections = 0): Closure
    {
        $streaming = null;

        return static function (RequestInterface $request, array $requestOptions) use ($fallback, $options, $maxIdleConnections, &$streaming): PromiseInterface {
            if (empty($requestOptions['stream']) || ! self::supports($requestOptions)) {
                return $fallback($request, $requestOptions);
            }

            $streaming ??= new self($options, $maxIdleConnections);

            return $streaming($request, $requestOptions);
        };
    }

    /**
     * Determine whether the current execution can use native streaming.
     */
    public static function supports(array $options): bool
    {
        return Coroutine::inCoroutine()
            && (Runtime::getHookFlags() & SWOOLE_HOOK_NATIVE_CURL) !== 0
            && function_exists('curl_multi_exec')
            && ! array_key_exists('stream_context', $options)
            && (! isset($options['stream_factory']) || $options['stream_factory'] instanceof HttpFactory);
    }

    /**
     * Start a transfer and return its response without draining its body.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        // Guzzle has consumed the stack; retaining it would keep its response alive.
        unset($options['handler']);

        try {
            HostValidator::assertRequestHost($request);

            if (isset($options['delay'])) {
                Sleep::usleep((int) ($options['delay'] * 1000));
            }

            $timeout = $this->timeout($options['timeout'] ?? 0, 'timeout');
            $readTimeout = $this->timeout($options['read_timeout'] ?? CurlStreamingBody::DEFAULT_READ_TIMEOUT, 'read_timeout');
            $startedAt = hrtime(true) / 1e9;
            $deadline = $timeout > 0 ? $startedAt + $timeout : null;

            foreach (['on_headers', 'on_stats'] as $name) {
                if (isset($options[$name]) && ! is_callable($options[$name])) {
                    throw new InvalidArgumentException("{$name} must be callable.");
                }
            }

            $buffer = new BufferStream;
            $handle = null;
            $sink = FnStream::decorate($buffer, [
                'write' => static function (string $chunk) use ($buffer, &$handle): int {
                    $buffer->write($chunk);

                    // Accept this chunk, then stop native reads until the consumer drains it.
                    /** @var CurlHandle $handle Assigned before the transfer starts. */
                    if (curl_pause($handle, CURLPAUSE_RECV) !== CURLE_OK) {
                        throw new RuntimeException('Unable to pause the streaming response.');
                    }

                    return strlen($chunk);
                },
            ]);
            $transferOptions = $options;
            unset($transferOptions['on_headers'], $transferOptions['on_stats']);
            $transferOptions['sink'] = $sink;
            // Streaming timeout ends at headers; body waits use read_timeout.
            $transferOptions['timeout'] = 0;
            $easy = $this->factory->create($request, $transferOptions);
            $handle = $easy->handle;

            try {
                $connection = $this->acquire($easy);
                $body = new CurlStreamingBody(
                    $this,
                    $this->factory,
                    $connection,
                    $easy,
                    $buffer,
                    $options,
                    $startedAt,
                    $deadline,
                    $readTimeout,
                );
            } catch (Throwable $exception) {
                $this->factory->release($easy);

                throw $exception;
            }

            return Create::promiseFor($body->response());
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return Create::rejectionFor($exception);
        }
    }

    /**
     * Obtain an exclusive transport without sharing another proxy's tunnel.
     */
    protected function acquire(EasyHandle $easy): CurlStreamingConnection
    {
        for ($index = count($this->idleConnections) - 1; $index >= 0; --$index) {
            $connection = $this->idleConnections[$index];

            if ($connection->proxyTunnelSignature === $easy->proxyTunnelSignature) {
                array_splice($this->idleConnections, $index, 1);

                return $connection;
            }
        }

        return new CurlStreamingConnection($easy->proxyTunnelSignature);
    }

    /**
     * Retain an idle transport after its request has been detached.
     */
    public function release(CurlStreamingConnection $connection): void
    {
        if ($this->maxIdleConnections === 0) {
            return;
        }

        if (count($this->idleConnections) >= $this->maxIdleConnections) {
            array_shift($this->idleConnections);
        }

        $this->idleConnections[] = $connection;
    }

    /**
     * Normalize a nonnegative request timeout in seconds.
     */
    protected function timeout(mixed $value, string $name): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException("{$name} must be a finite nonnegative number.");
        }

        return (float) $value;
    }
}
