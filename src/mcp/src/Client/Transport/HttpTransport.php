<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Transport;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\LineReader;
use Hypervel\Http\Client\Response as ClientResponse;
use Hypervel\Mcp\Client\Contracts\IsolatesExchanges;
use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Client\Contracts\UsesProtocol;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\Exceptions\TransportException;
use Hypervel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use Hypervel\Mcp\Enums\ProtocolHandshake;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Enums\RequestHeader;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\SessionExpiredException;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Swoole\Coroutine\CanceledException;
use Throwable;
use WeakMap;

class HttpTransport implements Transport, UsesProtocol, IsolatesExchanges
{
    /** @var null|(Closure(): ?string)|string */
    protected string|Closure|null $token = null;

    protected ?ProtocolVersion $protocolVersion = null;

    protected ?string $sessionId = null;

    protected bool $initialized = false;

    protected float $timeoutSeconds = 30.0;

    /** @var array<string, string> */
    protected array $customHeaders = [];

    /**
     * Keep the protected queue while replies belong to their calling coroutine.
     *
     * @var array<int, string>
     */
    protected array $queue {
        &get {
            return $this->exchange()->queue;
        }
        set {
            $this->exchange()->queue = $value;
        }
    }

    /** @var array<int, HttpExchange> */
    private array $exchanges = [];

    /**
     * Isolate pending bytes while readLine overrides still receive the original stream.
     *
     * @var null|WeakMap<StreamInterface, LineReader>
     */
    private ?WeakMap $lineReaders = null;

    private int $generation = 0;

    /**
     * Create an HTTP transport for the endpoint.
     */
    public function __construct(protected string $url)
    {
    }

    /**
     * Reset shared negotiation state for a new connection.
     */
    public function connect(): void
    {
        $this->reset();
    }

    /**
     * Terminate the session and invalidate exchanges for their owners to close.
     */
    public function disconnect(): void
    {
        try {
            $this->terminateSession();
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $this->reset();

            // A sibling may be reading its body; only its consumer may release the native transfer.
            foreach ($this->exchanges as $exchange) {
                $exchange->closed = true;
            }

            try {
                $this->endExchange();
            } catch (Throwable $cleanupException) {
                if (! isset($exception)
                    || ($cleanupException instanceof CanceledException && ! $exception instanceof CanceledException)) {
                    $exception = $cleanupException;
                }
            }

            if (isset($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * Set the HTTP request timeout.
     */
    public function setTimeoutSeconds(float $seconds): void
    {
        $this->timeoutSeconds = $seconds;
    }

    /**
     * Set the bearer token or its resolver.
     *
     * @param (Closure(): ?string)|string $token
     */
    public function withToken(#[SensitiveParameter] string|Closure $token): void
    {
        $this->token = $token;
    }

    /**
     * Add custom HTTP headers.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): void
    {
        $this->customHeaders = array_merge($this->customHeaders, $headers);
    }

    /**
     * Get the server endpoint URL.
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Get the configuration needed to recreate this transport.
     *
     * @return array<string, mixed>
     */
    public function recipe(): array
    {
        return [
            'driver' => 'http',
            'url' => $this->url,
            'token' => $this->token instanceof Closure ? (string) ($this->token)() : $this->token,
            'headers' => $this->customHeaders,
            'timeoutSeconds' => $this->timeoutSeconds,
        ];
    }

    /**
     * Send a message in an exchange owned by the calling coroutine.
     *
     * @param array<string, string> $headers
     */
    public function send(string $message, array $headers = []): void
    {
        $this->endExchange();
        $exchange = $this->exchange();
        $hadSession = $this->sessionId !== null;
        $timeoutSeconds = $this->timeoutSeconds;

        try {
            try {
                $response = Http::withHeaders($this->headers($headers))
                    ->withBody($message, 'application/json')
                    ->timeout($timeoutSeconds)
                    ->withOptions(['stream' => true])
                    ->post($this->url);
            } catch (ConnectionException $connectionException) {
                $this->failWith("HTTP request to [{$this->url}] failed: {$connectionException->getMessage()}");
            }

            if ($exchange->closed) {
                $response->close();

                throw new ClientException('The HTTP exchange was closed before its response arrived.');
            }

            $exchange->response = $response;

            if ($exchange->initializing && $exchange->generation === $this->generation) {
                $this->captureSessionId($response);
            }

            if ($response->status() === 401 || $response->status() === 403) {
                $challenge = WwwAuthenticateChallenge::parse($response->header('WWW-Authenticate'));

                $this->resetFailedExchange($exchange);

                throw new AuthorizationRequiredException(
                    "The server responded with HTTP {$response->status()} for endpoint [{$this->url}]. Authorization is required.",
                    $challenge,
                );
            }

            if ($response->notFound() && $hadSession) {
                $this->resetFailedExchange($exchange);

                throw new SessionExpiredException("Session expired. The server responded with HTTP 404 for endpoint [{$this->url}].");
            }

            if (! $response->successful()) {
                $content = trim($response->body());
                $exchange->ensureOpen();

                if ($this->hasJsonRpcError($content)) {
                    $this->queue[] = $content;

                    return;
                }

                if ($response->notFound() || ($response->serverError() && $response->status() !== 501)) {
                    $this->failWith("Unexpected HTTP status [{$response->status()}] from endpoint [{$this->url}].");
                }

                $this->resetFailedExchange($exchange);

                throw new TransportException("The endpoint [{$this->url}] rejected the request with HTTP status [{$response->status()}].");
            }

            if ($exchange->initializing && $exchange->generation === $this->generation) {
                $this->initialized = true;
            }

            if (str_contains($response->header('Content-Type'), 'text/event-stream')) {
                $exchange->streaming = true;

                return;
            }

            $content = trim($response->body());
            $exchange->ensureOpen();

            if ($response->accepted() || $content === '') {
                return;
            }

            $this->queue[] = $content;
        } catch (Throwable $exception) {
            try {
                $this->endExchange();
            } catch (Throwable $cleanupException) {
                if ($cleanupException instanceof CanceledException && ! $exception instanceof CanceledException) {
                    throw $cleanupException;
                }
            }

            throw $exception;
        }
    }

    /**
     * Set the protocol version used by subsequent requests.
     */
    public function useProtocol(ProtocolVersion $protocolVersion): void
    {
        $previous = $this->protocolVersion;

        if ($previous instanceof ProtocolVersion && $previous->handshake() !== $protocolVersion->handshake()) {
            $this->reset();
        }

        $this->protocolVersion = $protocolVersion;
    }

    /**
     * Receive the next message without waiting for the response body to end.
     */
    public function receive(): string
    {
        $exchange = $this->exchanges[Coroutine::id()] ?? null;
        $exchange?->ensureOpen();

        if ($exchange !== null && $exchange->queue === [] && $exchange->streaming && $exchange->response !== null) {
            $this->readSseStream($exchange->response);
        }

        $message = $exchange === null ? null : array_shift($exchange->queue);

        if ($message === null) {
            throw new ClientException('No message available from the HTTP transport.');
        }

        return $message;
    }

    /**
     * Close the calling coroutine's response without affecting sibling exchanges.
     */
    public function endExchange(): void
    {
        $owner = Coroutine::id();
        $exchange = $this->exchanges[$owner] ?? null;
        unset($this->exchanges[$owner]);
        $exchange?->close();
    }

    /**
     * Disconnect any remaining session and responses.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Determine whether an HTTP error contains a protocol error response.
     */
    protected function hasJsonRpcError(string $content): bool
    {
        $body = json_decode($content, true);

        return is_array($body) && Arr::get($body, 'jsonrpc') === '2.0' && is_array(Arr::get($body, 'error'));
    }

    /**
     * Build headers from the current connection and credentials.
     *
     * @param array<string, string> $mirrored
     * @return array<string, string>
     */
    protected function headers(array $mirrored = []): array
    {
        $customHeaders = $this->customHeaders;
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            ...$this->eraHeaders(),
            ...$mirrored,
        ];

        $token = $this->token instanceof Closure ? (string) ($this->token)() : $this->token;

        if ($token !== null && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        foreach ($customHeaders as $name => $value) {
            if ($this->reserved($name)) {
                continue;
            }

            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Determine whether the header belongs to the MCP protocol.
     */
    protected function reserved(string $name): bool
    {
        return str_starts_with(strtolower($name), 'mcp-');
    }

    /**
     * Get the headers required by this protocol era.
     *
     * @return array<string, string>
     */
    protected function eraHeaders(): array
    {
        $version = $this->protocolVersion;

        if ($version?->handshake() === ProtocolHandshake::Discovery) {
            return [RequestHeader::PROTOCOL_VERSION->value => $version->value];
        }

        $headers = [];

        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
        }

        if ($this->initialized && $version instanceof ProtocolVersion) {
            $headers[RequestHeader::PROTOCOL_VERSION->value] = $version->value;
        }

        return $headers;
    }

    /**
     * Capture the legacy initialization session identifier.
     */
    protected function captureSessionId(ClientResponse $response): void
    {
        if ($this->protocolVersion?->handshake() !== ProtocolHandshake::Initialize) {
            return;
        }

        $sessionId = $response->header('MCP-Session-Id');

        if ($sessionId !== '') {
            $this->sessionId = $sessionId;
        }
    }

    /**
     * Read one complete SSE message into the current exchange.
     */
    protected function readSseStream(ClientResponse $response): void
    {
        $exchange = $this->exchange();
        $stream = $response->toPsrResponse()->getBody();
        $data = [];

        while (true) {
            $line = $this->readLine($stream);
            $exchange->ensureOpen();

            if ($line === '') {
                if ($stream->eof()) {
                    break;
                }

                continue;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                if ($data !== []) {
                    $this->queueSseEvent(implode("\n", $data));
                    if ($this->queue !== []) {
                        return;
                    }
                    $data = [];
                }
            } elseif ($line === 'data' || str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
            }
        }

        if ($data !== []) {
            $this->queueSseEvent(implode("\n", $data));
        }
    }

    /**
     * Read one line without waiting for a larger body buffer to fill.
     */
    protected function readLine(StreamInterface $stream): string
    {
        $this->lineReaders ??= new WeakMap;

        return ($this->lineReaders[$stream] ??= new LineReader)->readLine($stream);
    }

    /**
     * Queue an SSE payload while rejecting unsupported server requests.
     */
    protected function queueSseEvent(string $data): void
    {
        if ($data === '') {
            return;
        }

        $decoded = json_decode($data, true);

        if (is_array($decoded) && isset($decoded['method'], $decoded['id'])) {
            $this->failWith('The server initiated a request over the SSE stream, which this HTTP client does not support.');
        }

        $this->queue[] = $data;
    }

    /**
     * Terminate an active legacy session.
     */
    protected function terminateSession(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        try {
            Http::withHeaders($this->headers())
                ->timeout($this->timeoutSeconds)
                ->delete($this->url);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
        }
    }

    /**
     * Reset shared negotiation without closing already-sent sibling responses.
     */
    protected function reset(): void
    {
        ++$this->generation;
        $this->protocolVersion = null;
        $this->sessionId = null;
        $this->initialized = false;
    }

    /**
     * Forget this exchange's failed session and report its error.
     */
    protected function failWith(string $message): never
    {
        $exchange = $this->exchanges[Coroutine::id()] ?? null;

        if ($exchange !== null) {
            $this->resetFailedExchange($exchange);
        }

        throw new ClientException($message);
    }

    /**
     * Reset only the negotiation that produced the failed exchange.
     */
    private function resetFailedExchange(HttpExchange $exchange): void
    {
        if ($exchange->generation === $this->generation) {
            $this->reset();
        }
    }

    /**
     * Get or start the calling coroutine's exchange.
     */
    private function exchange(): HttpExchange
    {
        return $this->exchanges[Coroutine::id()] ??= new HttpExchange(
            $this->generation,
            ! $this->initialized && $this->protocolVersion?->handshake() === ProtocolHandshake::Initialize,
        );
    }
}
