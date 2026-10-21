<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Engine\Channel;
use Hypervel\Mcp\Client\Contracts\IsolatesExchanges;
use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Contracts\MirrorsParameters;
use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Client\Contracts\UsesProtocol;
use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\Exceptions\TransportException;
use Hypervel\Mcp\Client\Methods\Discover;
use Hypervel\Mcp\Client\Methods\Initialize;
use Hypervel\Mcp\Client\Methods\Ping;
use Hypervel\Mcp\Client\Schema\DiscoverResult;
use Hypervel\Mcp\Client\Schema\InitializeResult;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Enums\ProtocolHandshake;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Exceptions\SessionExpiredException;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Transport\JsonRpcNotification;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Support\Arr;
use JsonException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class Protocol
{
    protected bool $connected = false;

    protected bool $connecting = false;

    protected int $nextRequestId = 1;

    protected ?NegotiatedConnection $connection = null;

    protected ?ResponseCache $cache = null;

    /** @var null|Channel<true> */
    private ?Channel $guard = null;

    /**
     * Create a protocol connection for the transport.
     */
    public function __construct(
        protected Transport $transport,
        protected Implementation $clientInfo,
        protected ?ProtocolVersion $pinnedProtocolVersion = null,
    ) {
    }

    /**
     * Determine whether the connection has completed negotiation.
     */
    public function connected(): bool
    {
        return $this->connected;
    }

    /**
     * Get the legacy initialization result.
     */
    public function initializeResult(): ?InitializeResult
    {
        return $this->connection?->initializeResult();
    }

    /**
     * Get the modern discovery result.
     */
    public function discoverResult(): ?DiscoverResult
    {
        return $this->connection?->discoverResult();
    }

    /**
     * Get the negotiated server capabilities.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->connection?->capabilities() ?? [];
    }

    /**
     * Get the server implementation information.
     */
    public function serverInfo(): ?Implementation
    {
        return $this->connection?->serverInfo();
    }

    /**
     * Get the server instructions.
     */
    public function instructions(): ?string
    {
        return $this->connection?->instructions();
    }

    /**
     * Pin the protocol version and invalidate the existing negotiation.
     */
    public function pinProtocolVersion(?ProtocolVersion $protocolVersion): void
    {
        $this->synchronized(function () use ($protocolVersion): void {
            $this->pinnedProtocolVersion = $protocolVersion;
            $this->connection = null;

            if ($this->connected) {
                $this->disconnectUnlocked();
            }
        });
    }

    /**
     * Get the explicitly selected protocol version.
     */
    public function pinnedProtocolVersion(): ?ProtocolVersion
    {
        return $this->pinnedProtocolVersion;
    }

    /**
     * Connect and negotiate once for concurrent callers.
     */
    public function connect(): void
    {
        // Releasing the guard can wake a caller that invalidates the connection.
        while (! $this->connected) {
            $this->synchronized($this->connectUnlocked(...));
        }
    }

    /**
     * Connect while the caller holds the protocol guard.
     */
    private function connectUnlocked(): void
    {
        if ($this->connected) {
            return;
        }

        $this->transport->connect();
        $this->connecting = true;

        try {
            $this->handshake();
        } catch (Throwable $throwable) {
            if (! $this->transport instanceof IsolatesExchanges) {
                $this->cleanup($this->disconnectUnlocked(...), $throwable);
            }

            throw $throwable;
        } finally {
            $this->connecting = false;
        }

        $this->connected = true;
    }

    /**
     * Negotiate the pinned, remembered or preferred protocol version.
     */
    protected function handshake(): void
    {
        $pinned = $this->pinnedProtocolVersion;

        if ($pinned instanceof ProtocolVersion) {
            $this->connection = $pinned->handshake() === ProtocolHandshake::Discovery
                ? $this->discover($pinned)
                : $this->initialize($pinned, true);

            return;
        }

        $remembered = $this->connection?->protocolVersion;

        if ($remembered?->handshake() === ProtocolHandshake::Initialize) {
            try {
                $this->connection = $this->initialize($remembered);

                return;
            } catch (OAuthException|CanceledException $oAuthException) {
                throw $oAuthException;
            } catch (Throwable) {
                $this->connection = null;

                $this->transport->connect();
            }
        }

        $this->connection = $this->probe();
    }

    /**
     * Probe modern discovery before falling back to legacy initialization.
     */
    protected function probe(): NegotiatedConnection
    {
        try {
            return $this->discover();
        } catch (JsonRpcException $jsonRpcException) {
            if ($this->identifiesModernServer($jsonRpcException)) {
                return $this->retryWithMutualVersion($jsonRpcException);
            }

            if (! $this->identifiesLegacyServer($jsonRpcException)) {
                throw $jsonRpcException;
            }

            $rejection = null;
        } catch (TransportException $transportException) {
            $rejection = $transportException;
        }

        try {
            $this->transport->connect();

            return $this->initialize(ProtocolVersion::V2025_11_25);
        } catch (OAuthException|CanceledException $oAuthException) {
            throw $oAuthException;
        } catch (Throwable $throwable) {
            throw $rejection instanceof ClientException
                ? new ClientException(sprintf(
                    '%s The legacy handshake also failed: %s',
                    $rejection->getMessage(),
                    $throwable->getMessage(),
                ), 0, $throwable)
                : $throwable;
        }
    }

    /**
     * Complete the legacy initialization handshake.
     */
    protected function initialize(ProtocolVersion $protocolVersion, bool $pinned = false): NegotiatedConnection
    {
        $result = InitializeResult::from($this->attempt(
            new Initialize($this->clientInfo, $protocolVersion),
            $protocolVersion,
        ));
        $settled = ProtocolVersion::from($result->protocolVersion);

        if ($pinned && $settled !== $protocolVersion) {
            throw $this->versionMismatch($settled, $protocolVersion);
        }

        $this->notifyUnlocked('notifications/initialized', $settled);

        return new NegotiatedConnection($settled, $result);
    }

    /**
     * Discover the server and settle on a mutually supported version.
     */
    protected function discover(?ProtocolVersion $pinned = null): NegotiatedConnection
    {
        $offered = $pinned ?? ProtocolVersion::LATEST;
        $result = DiscoverResult::from($this->attempt(new Discover, $offered));
        $settled = ProtocolVersion::preferredFrom(...$result->supportedVersions);

        if (! $settled instanceof ProtocolVersion) {
            throw new ClientException(sprintf(
                'The server supports protocol versions [%s]. This client supports [%s].',
                implode(', ', $result->supportedVersions),
                implode(', ', ProtocolVersion::clientSupported()),
            ));
        }

        if ($pinned instanceof ProtocolVersion && $settled !== $pinned) {
            throw $this->versionMismatch($settled, $pinned);
        }

        return $settled->handshake() === ProtocolHandshake::Initialize
            ? $this->initialize($settled)
            : new NegotiatedConnection($settled, $result);
    }

    /**
     * Initialize a mutually supported version from a discovery rejection.
     */
    protected function retryWithMutualVersion(JsonRpcException $jsonRpcException): NegotiatedConnection
    {
        $data = $jsonRpcException->data();
        $supported = is_array($data) ? Arr::get($data, 'supported') : null;

        $protocolVersion = is_array($supported)
            ? ProtocolVersion::preferredFrom(...array_values(array_filter($supported, is_string(...))))
            : null;

        if (! $protocolVersion instanceof ProtocolVersion || $protocolVersion->handshake() !== ProtocolHandshake::Initialize) {
            throw $jsonRpcException;
        }

        return $this->initialize($protocolVersion);
    }

    /**
     * Create an error for a server rejecting the pinned version.
     */
    protected function versionMismatch(ProtocolVersion $settled, ProtocolVersion $pinned): ClientException
    {
        return new ClientException(sprintf(
            'The server settled on protocol version [%s] while [%s] was requested.',
            $settled->value,
            $pinned->value,
        ));
    }

    /**
     * Determine whether an error identifies a modern server.
     */
    protected function identifiesModernServer(JsonRpcException $jsonRpcException): bool
    {
        return in_array($jsonRpcException->getCode(), [
            ErrorCode::HEADER_MISMATCH->value,
            ErrorCode::MISSING_REQUIRED_CLIENT_CAPABILITY->value,
            ErrorCode::UNSUPPORTED_PROTOCOL_VERSION->value,
        ], true);
    }

    /**
     * Determine whether an error permits a legacy handshake.
     */
    protected function identifiesLegacyServer(JsonRpcException $jsonRpcException): bool
    {
        $code = $jsonRpcException->getCode();

        return in_array($code, [
            ErrorCode::PARSE_ERROR->value,
            ErrorCode::INVALID_REQUEST->value,
            ErrorCode::METHOD_NOT_FOUND->value,
        ], true) || ($code <= -32000 && $code >= -32099);
    }

    /**
     * Disconnect the transport after any active negotiation finishes.
     */
    public function disconnect(): void
    {
        $this->synchronized($this->disconnectUnlocked(...));
    }

    /**
     * Disconnect while the caller holds the protocol guard.
     */
    private function disconnectUnlocked(): void
    {
        $this->connected = false;

        $this->transport->disconnect();
    }

    /**
     * Dispatch a method, using the response cache when configured.
     *
     * @param Method<mixed> $method
     * @return array<string, mixed>
     */
    public function dispatch(Method $method): array
    {
        if (! $this->cache instanceof ResponseCache) {
            return $this->roundTrip($method);
        }

        return $this->cache->remember(
            $method,
            $this->transport,
            fn (): array => $this->roundTrip($method),
        );
    }

    /**
     * Set the response cache for this client.
     */
    public function useCache(?ResponseCache $responseCache): void
    {
        $this->cache = $responseCache;
    }

    /**
     * Get the configured response cache.
     */
    public function cache(): ?ResponseCache
    {
        return $this->cache;
    }

    /**
     * Send a complete request, retrying an expired session once.
     *
     * @param Method<mixed> $method
     * @return array<string, mixed>
     */
    protected function roundTrip(Method $method): array
    {
        if (! $this->transport instanceof IsolatesExchanges) {
            return $this->synchronized(function () use ($method): array {
                $this->connectUnlocked();

                try {
                    return $this->attempt($method, $this->connectionProtocol());
                } catch (SessionExpiredException) {
                    $this->connectUnlocked();

                    return $this->attempt($method, $this->connectionProtocol());
                }
            });
        }

        $this->connect();

        try {
            return $this->attempt($method, $this->connectionProtocol());
        } catch (SessionExpiredException) {
            $this->connect();

            return $this->attempt($method, $this->connectionProtocol());
        }
    }

    /**
     * Send a request and consume its matching terminal response.
     *
     * @param Method<mixed> $method
     * @return array<string, mixed>
     */
    protected function attempt(Method $method, ProtocolVersion $protocolVersion): array
    {
        $connection = $this->connection;
        $this->configureTransport($protocolVersion);

        $request = new JsonRpcRequest(
            id: $this->nextRequestId++,
            // Modern liveness checks use discovery; select per attempt while keeping ping uncached.
            method: $method instanceof Ping && $protocolVersion->handshake() === ProtocolHandshake::Discovery
                ? 'server/discover'
                : $method->method(),
            params: $this->params($method, $protocolVersion),
        );

        $json = $request->toJson();
        $headers = $this->requestHeaders($method, $request, $protocolVersion);

        try {
            $this->transport->send($json, $headers);

            do {
                $raw = $this->transport->receive();

                try {
                    $response = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $jsonException) {
                    throw new ClientException(
                        'Malformed JSON-RPC response from server: ' . $jsonException->getMessage(),
                        0,
                        $jsonException,
                    );
                }

                if (! is_array($response) || Arr::get($response, 'jsonrpc') !== '2.0') {
                    throw new ClientException('Invalid JSON-RPC response from server.');
                }

                $this->handleServerRequest($response);

                // Each direction allocates request IDs independently.
                $responseId = Arr::get($response, 'id');
            } while (is_string(Arr::get($response, 'method'))
                || ($responseId !== $request->id && ($responseId !== null || ! Arr::has($response, 'error'))));

            $hasResult = Arr::has($response, 'result');
            $hasError = Arr::has($response, 'error');
            $error = Arr::get($response, 'error');

            if ($hasResult === $hasError) {
                throw new ClientException('Invalid JSON-RPC response: must contain exactly one of "result" or "error".');
            }

            if ($hasError && ! is_array($error)) {
                throw new ClientException('Invalid JSON-RPC error payload.');
            }
        } catch (Throwable $throwable) {
            if ($this->transport instanceof IsolatesExchanges) {
                // An older exchange must not invalidate a replacement negotiation.
                if ($this->connection === $connection) {
                    $this->connected = false;
                }
            } elseif ($this->connected) {
                $this->cleanup($this->disconnectUnlocked(...), $throwable);
            }

            throw $throwable;
        } finally {
            if ($this->transport instanceof IsolatesExchanges) {
                $this->cleanup($this->transport->endExchange(...), $throwable ?? null);
            }
        }

        if ($hasError) {
            $message = Arr::get($error, 'message', 'Unknown JSON-RPC error.');
            $code = Arr::get($error, 'code', 0);
            $data = Arr::get($error, 'data');

            throw new JsonRpcException(
                is_string($message) ? $message : 'Unknown JSON-RPC error.',
                is_int($code) ? $code : 0,
                Arr::get($response, 'id'),
                $data,
            );
        }

        $result = Arr::get($response, 'result');

        return is_array($result) ? $result : [];
    }

    /**
     * Get the headers required by the selected protocol version.
     *
     * @param Method<mixed> $method
     * @return array<string, string>
     */
    protected function requestHeaders(Method $method, JsonRpcRequest $request, ProtocolVersion $protocolVersion): array
    {
        if ($protocolVersion->handshake() !== ProtocolHandshake::Discovery) {
            return [];
        }

        return [
            ...$request->mirroredHeaders(),
            ...$method instanceof MirrorsParameters ? $method->requestHeaders() : [],
        ];
    }

    /**
     * Add protocol metadata to the method parameters when required.
     *
     * @param Method<mixed> $method
     * @return array<string, mixed>
     */
    protected function params(Method $method, ProtocolVersion $protocolVersion): array
    {
        $params = $method->params();

        if ($protocolVersion->handshake() !== ProtocolHandshake::Discovery) {
            return $params;
        }

        $params['_meta'] = [
            MetaKey::PROTOCOL_VERSION->value => $protocolVersion->value,
            MetaKey::CLIENT_CAPABILITIES->value => (object) [],
            MetaKey::CLIENT_INFO->value => $this->clientInfo->toArray(),
            ...Arr::wrap(Arr::get($params, '_meta')),
        ];

        return $params;
    }

    /**
     * Send a notification without overlapping connection mutations.
     */
    public function notify(string $method, ?ProtocolVersion $protocolVersion = null): void
    {
        $this->synchronized(fn () => $this->notifyUnlocked($method, $protocolVersion));
    }

    /**
     * Send a notification while the caller holds the protocol guard.
     */
    private function notifyUnlocked(string $method, ?ProtocolVersion $protocolVersion = null): void
    {
        $this->configureTransport($protocolVersion ?? $this->connectionProtocol());

        $notification = new JsonRpcNotification($method, []);

        try {
            $this->transport->send($notification->toJson());
        } catch (Throwable $throwable) {
            throw $throwable;
        } finally {
            if ($this->transport instanceof IsolatesExchanges) {
                $this->cleanup($this->transport->endExchange(...), $throwable ?? null);
            }
        }
    }

    /**
     * Get the currently negotiated protocol version.
     */
    public function connectionProtocol(): ProtocolVersion
    {
        if (! $this->connection instanceof NegotiatedConnection) {
            throw new ClientException('The client has not negotiated a protocol version.');
        }

        return $this->connection->protocolVersion;
    }

    /**
     * Apply the protocol version to transports that use it.
     */
    protected function configureTransport(ProtocolVersion $protocolVersion): void
    {
        if ($this->transport instanceof UsesProtocol) {
            $this->transport->useProtocol($protocolVersion);
        }
    }

    /**
     * Reply to server requests supported by the transport.
     *
     * @param array<string, mixed> $frame
     */
    protected function handleServerRequest(array $frame): void
    {
        $id = Arr::get($frame, 'id');
        $method = Arr::get($frame, 'method');

        if (! is_string($method) || (! is_int($id) && ! is_string($id))) {
            return;
        }

        if ($method === 'ping') {
            $this->transport->send(JsonRpcResponse::result($id, [])->toJson());

            return;
        }

        $this->transport->send(JsonRpcResponse::error(
            $id,
            -32601,
            "Method [{$method}] not supported by this client.",
        )->toJson());
    }

    /**
     * Clean up without replacing a primary failure, except for cancellation.
     *
     * @param Closure(): void $cleanup
     */
    private function cleanup(Closure $cleanup, ?Throwable $primary = null): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            if ($primary === null
                || ($exception instanceof CanceledException && ! $primary instanceof CanceledException)) {
                throw $exception;
            }
        }
    }

    /**
     * Serialize connection mutations and transports without isolated exchanges.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @return TResult
     */
    private function synchronized(Closure $callback): mixed
    {
        if (! Coroutine::inCoroutine()) {
            return $callback();
        }

        $guard = $this->guard ??= new Channel(1);

        if ($guard->isFull()) {
            ConnectionResolver::releaseIdleConnections();
        }

        if (! $guard->push(true)) {
            throw new CanceledException('Waiting for the MCP connection was canceled.');
        }

        try {
            return $callback();
        } finally {
            $guard->pop();
        }
    }
}
