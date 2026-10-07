<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\BufferStream;
use GuzzleHttp\TransferStats;
use Hypervel\Engine\Coroutine;
use Hypervel\ObjectPool\PoolErrorReporter;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Drive one transfer from the response consumer, including across coroutine handoff.
 *
 * @internal
 */
class CurlStreamingBody implements StreamInterface
{
    public const float DEFAULT_READ_TIMEOUT = 60.0;

    protected bool $attached = false;

    protected bool $completed = false;

    protected bool $closed = false;

    protected bool $exposed = false;

    protected bool $timedOut = false;

    protected int $position = 0;

    protected ?ResponseInterface $completedResponse = null;

    protected ?TransferStats $completedStats = null;

    /** @var null|list<array<string, list<string>>|RequestInterface|ResponseInterface> */
    protected ?array $pendingTrailerArguments = null;

    protected RequestInterface $request;

    /**
     * Take ownership of a configured transfer and its bounded receive buffer.
     */
    public function __construct(
        protected CurlStreamingHandler $handler,
        protected CurlFactoryInterface $factory,
        protected ?CurlStreamingConnection $connection,
        protected ?EasyHandle $easy,
        protected BufferStream $buffer,
        protected bool &$paused,
        protected array $options,
        protected float $startedAt,
        protected ?float $headerDeadline,
        protected float $readTimeout,
    ) {
        $this->request = $easy->request;
    }

    /**
     * Receive the final headers and expose a caller-owned streaming body.
     */
    public function response(): ResponseInterface
    {
        $response = null;

        try {
            if (curl_multi_add_handle($this->connection->handle, $this->easy->handle) !== CURLM_OK) {
                throw new RuntimeException('Unable to start the streaming request.');
            }

            $this->attached = true;
            $idleDeadline = $this->readTimeout > 0 ? $this->startedAt + $this->readTimeout : null;
            $auth = $this->options['curl'] ?? [];
            $negotiatesAuth = isset($auth[CURLOPT_HTTPAUTH]) || isset($auth[CURLOPT_PROXYAUTH]);

            while (! $this->completed) {
                $this->advance();
                $response = $this->easy?->response;

                if ($response !== null
                    && ($response->getStatusCode() >= 200 || $response->getStatusCode() === 101)
                    && (! $negotiatesAuth || ! $this->buffer->eof())
                ) {
                    break;
                }

                if (! $this->completed) {
                    $this->wait($idleDeadline, headers: true);
                }
            }

            $response = $this->easy->response ?? $this->completedResponse;

            // A pre-header retry creates and exposes its own streaming response.
            if ($response->getBody() instanceof self) {
                return $response;
            }

            $response = $response->withBody($this);
            $this->exposed = true;

            if ($this->headerDeadline !== null && hrtime(true) / 1e9 >= $this->headerDeadline) {
                $this->timedOut = true;

                throw $this->responseException('Timed out while receiving the response headers', $response, timeout: true);
            }

            $onHeaders = $this->options['on_headers'] ?? null;
            unset($this->options['on_headers']);

            if ($onHeaders !== null) {
                try {
                    $onHeaders($response, $this->request);
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw $this->responseException('An error was encountered during the on_headers event', $response, $exception);
                }
            }

            $trailerArguments = $this->pendingTrailerArguments;
            $this->pendingTrailerArguments = null;

            if ($trailerArguments !== null) {
                $onTrailers = $this->options['on_trailers'];
                unset($this->options['on_trailers']);

                try {
                    $onTrailers(...$trailerArguments);
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw $this->responseException('An error was encountered during the on_trailers event', $response, $exception);
                }
            }

            $this->reportStats($response);

            return $response;
        } catch (Throwable $exception) {
            try {
                $this->reportStats($this->exposed ? $response : null, $exception);
            } finally {
                $this->close();
            }

            throw $exception;
        }
    }

    /**
     * Create a transport failure that retains the received response.
     */
    protected function responseException(string $message, ResponseInterface $response, ?Throwable $previous = null, bool $timeout = false): TransferException
    {
        if (class_exists(ResponseException::class)) {
            return $timeout
                ? new ResponseTimeoutException($message, $this->request, $response, $previous)
                : new ResponseException($message, $this->request, $response, $previous);
        }

        return new GuzzleRequestException($message, $this->request, $response, $previous); // @phpstan-ignore argument.type (Guzzle 7 accepts the response before the cause.)
    }

    /**
     * Read available bytes without waiting to fill the requested length.
     */
    public function read(int $length): string
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot read a closed streaming response.');
        }

        if ($length < 0) {
            throw new RuntimeException('Length must be nonnegative.');
        }

        if ($length === 0) {
            return '';
        }

        try {
            if ($this->buffer->eof() && ! $this->completed) {
                $deadline = $this->readTimeout > 0 ? hrtime(true) / 1e9 + $this->readTimeout : null;

                if ($this->paused) {
                    // Resuming may synchronously fill the buffer and pause the sink again.
                    $this->paused = false;

                    if (curl_pause($this->easy->handle, CURLPAUSE_CONT) !== CURLE_OK) {
                        throw new RuntimeException('Unable to resume the streaming response.');
                    }
                }

                while ($this->buffer->eof() && ! $this->completed) {
                    $this->advance();

                    if ($this->buffer->eof() && ! $this->completed) {
                        $this->wait($deadline);
                    }
                }
            }

            $chunk = $this->buffer->read($length);
            $this->position += strlen($chunk);

            return $chunk;
        } catch (Throwable $exception) {
            $this->close();

            throw $exception;
        }
    }

    /**
     * Drive native I/O and settle a completed transfer once.
     */
    protected function advance(): void
    {
        $result = curl_multi_exec($this->connection->handle, $running);

        if (Coroutine::isCanceled()) {
            throw new CanceledException('The streaming request was canceled.');
        }

        if ($result !== CURLM_OK) {
            throw new RuntimeException('Unable to read the streaming response: ' . curl_multi_strerror($result));
        }

        $message = curl_multi_info_read($this->connection->handle);

        if ($message === false) {
            return;
        }

        $easy = $this->easy;
        $easy->errno = $message['result'];
        $this->detachTransfer();
        $this->completed = true;
        $this->easy = null;

        $retry = function (RequestInterface $request, array $options): PromiseInterface {
            if ($this->exposed) {
                return Create::rejectionFor(new RuntimeException('Cannot retry a streaming request after exposing its response.'));
            }

            $options = array_replace($this->options, $options);

            // Factory retry options contain temporary statistics and trailer collectors.
            foreach (['on_headers', 'on_stats', 'on_trailers'] as $name) {
                if (isset($this->options[$name])) {
                    $options[$name] = $this->options[$name];
                } else {
                    unset($options[$name]);
                }
            }

            if ($this->headerDeadline !== null) {
                $remaining = $this->headerDeadline - hrtime(true) / 1e9;

                if ($remaining <= 0) {
                    return Create::rejectionFor(new ConnectException('The streaming request timed out before response headers.', $request));
                }

                $options['timeout'] = $remaining;
            }

            // The replacement transfer owns callbacks and header-time statistics.
            unset($this->options['on_headers'], $this->options['on_stats'], $this->options['on_trailers']);

            return ($this->handler)($request, $options);
        };

        $stats = null;
        $trailerArguments = null;

        if (! $this->exposed && isset($easy->options['on_trailers'])) {
            // Completion before exposure must not publish trailers before on_headers.
            $easy->options['on_trailers'] = static function (array|ResponseInterface|RequestInterface ...$arguments) use (&$trailerArguments): void {
                $trailerArguments = $arguments;
            };
        }

        if (isset($this->options['on_stats'])) {
            $easy->options['on_stats'] = static function (TransferStats $transfer) use (&$stats): void {
                $stats = $transfer;
            };
        }

        try {
            $this->completedResponse = CurlFactory::finish($retry, $easy, $this->factory)->wait();
        } catch (TransferException $exception) {
            // Completion callbacks run after the native cancellation check above.
            if ($exception->getPrevious() instanceof CanceledException) {
                throw $exception->getPrevious();
            }

            throw $exception;
        } finally {
            $this->completedStats = $stats;
            $this->pendingTrailerArguments = $trailerArguments;
        }
    }

    /**
     * Wait for network progress within the current phase's deadline.
     */
    protected function wait(?float &$idleDeadline, bool $headers = false): void
    {
        $deadline = $idleDeadline;

        if ($headers && $this->headerDeadline !== null) {
            $deadline = $deadline === null ? $this->headerDeadline : min($deadline, $this->headerDeadline);
        }

        $remaining = $deadline === null ? 1.0 : $deadline - hrtime(true) / 1e9;

        if ($remaining <= 0) {
            $this->timedOut = true;

            if ($headers) {
                throw new ConnectException('The streaming request timed out before response headers.', $this->request);
            }

            throw new RuntimeException('The streaming response read timed out.');
        }

        $selected = curl_multi_select($this->connection->handle, min(1.0, $remaining));

        if (Coroutine::isCanceled()) {
            throw new CanceledException('The streaming request was canceled.');
        }

        if ($selected === -1) {
            // libcurl can briefly have no descriptors while scheduling its next action.
            usleep(1000);
        } elseif ($selected > 0 && $this->readTimeout > 0) {
            $idleDeadline = hrtime(true) / 1e9 + $this->readTimeout;
        }
    }

    /**
     * Report streaming statistics once, at the handler-return boundary.
     */
    protected function reportStats(?ResponseInterface $response, ?Throwable $error = null): void
    {
        $callback = $this->options['on_stats'] ?? null;
        unset($this->options['on_stats']);
        $stats = $this->completedStats;
        $this->completedStats = null;

        if ($callback !== null) {
            $handlerStats = $stats?->getHandlerStats() ?? [];

            if ($this->easy !== null) {
                $handlerStats = curl_getinfo($this->easy->handle);
                $handlerStats['appconnect_time'] = curl_getinfo($this->easy->handle, CURLINFO_APPCONNECT_TIME);
            }

            $handlerError = $stats?->getHandlerErrorData() ?? 0;

            $callback(new TransferStats(
                $this->request,
                $response ?? $stats?->getResponse(),
                hrtime(true) / 1e9 - $this->startedAt,
                $handlerError === 0 ? ($error ?? 0) : $handlerError,
                $handlerStats,
            ));
        }
    }

    /**
     * Remove the active handle before returning its native connection cache.
     */
    protected function detachTransfer(): void
    {
        $connection = $this->connection;
        $this->connection = null;

        if ($connection === null) {
            return;
        }

        if ($this->attached) {
            $this->attached = false;

            if (curl_multi_remove_handle($connection->handle, $this->easy->handle) !== CURLM_OK) {
                throw new RuntimeException('Unable to detach the streaming request.');
            }
        }

        $this->handler->release($connection);
    }

    /**
     * Close the stream and settle its transfer without waiting for more data.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            $this->detachTransfer();
        } finally {
            if ($this->easy !== null) {
                $easy = $this->easy;
                $this->easy = null;
                $this->factory->release($easy);
            }

            $this->buffer->close();
            $this->completedResponse = null;
            $this->completedStats = null;
            $this->pendingTrailerArguments = null;
            $this->options = [];
        }
    }

    /**
     * Detach the body, which has no PHP stream resource to return.
     */
    public function detach(): mixed
    {
        $this->close();

        return null;
    }

    /**
     * Get the unknown size of the incremental response body.
     */
    public function getSize(): ?int
    {
        return null;
    }

    /**
     * Get the number of bytes consumed by the caller.
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * Determine whether the completed body has been consumed.
     */
    public function eof(): bool
    {
        return $this->closed || ($this->completed && $this->buffer->eof());
    }

    /**
     * Determine whether the stream can seek.
     */
    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Reject seeking on an incremental response.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Cannot seek a streaming response.');
    }

    /**
     * Reject rewinding an incremental response.
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Determine whether the response accepts writes.
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Reject writes to the response body.
     */
    public function write(string $string): int
    {
        throw new RuntimeException('Cannot write to a streaming response.');
    }

    /**
     * Determine whether the stream is open for reading.
     */
    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    /**
     * Read the remaining response body.
     */
    public function getContents(): string
    {
        $contents = '';

        while (! $this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    /**
     * Get metadata for the forward-only response stream.
     */
    public function getMetadata(?string $key = null): mixed
    {
        $metadata = ['timed_out' => $this->timedOut, 'seekable' => false, 'eof' => $this->eof()];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    /**
     * Read the remaining response body as a string.
     */
    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * Release a response abandoned by its consumer.
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        }
    }
}
