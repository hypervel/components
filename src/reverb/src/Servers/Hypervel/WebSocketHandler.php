<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Reverb\Connection as ReverbConnection;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\WebSocketServer\Sender;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WebSocketHandler implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    /**
     * Active connections mapped by file descriptor.
     *
     * @var array<int, ConnectionLifecycle>
     */
    protected static array $connections = [];

    /**
     * Create a new WebSocket handler instance.
     */
    public function __construct(
        protected Container $container,
        protected PusherServer $server,
        protected ApplicationProvider $applications,
    ) {
    }

    /**
     * Handle a regular HTTP request to the WebSocket endpoint.
     *
     * Only reached when the route is matched on the HTTP server (no WebSocket
     * upgrade). The WS handshake path uses dispatchToCallback() which bypasses
     * this method entirely.
     */
    public function __invoke(HttpRequest $request, string $appKey): Response
    {
        return new Response('Upgrade Required', 426, ['Upgrade' => 'websocket']);
    }

    /**
     * Handle a new WebSocket connection.
     *
     * Resolve the app key from the route, create a Reverb Connection,
     * store it by fd, and delegate to the Pusher protocol server.
     */
    public function onOpen(WebSocketServer $server, Request $request): void
    {
        $httpRequest = RequestContext::get();
        $appKey = $httpRequest->route()->parameter('appKey');
        $lifecycle = new ConnectionLifecycle($request->fd);

        static::$connections[$request->fd] = $lifecycle;

        try {
            $lifecycle->run(function (ConnectionLifecycle $lifecycle) use ($appKey, $httpRequest, $request, $server): void {
                try {
                    $application = $this->applications->findByKey($appKey);
                } catch (InvalidApplication) {
                    $server->push(
                        $request->fd,
                        '{"event":"pusher:error","data":"{\"code\":4001,\"message\":\"Application does not exist\"}"}'
                    );

                    return;
                }

                $wsConnection = new Connection(
                    $this->container->make(Sender::class),
                    $request->fd,
                );

                $reverbConnection = new ReverbConnection(
                    $wsConnection,
                    $application,
                    $httpRequest->headers->get('Origin'),
                );

                $lifecycle->attach($reverbConnection);
                $this->server->open($reverbConnection);
            });
        } finally {
            if (! $lifecycle->connection()?->isEstablished()) {
                $terminal = static::takeConnection($request->fd, $lifecycle);

                if ($terminal !== null) {
                    $this->closeLifecycle($terminal, fn (): bool => $server->disconnect($request->fd));
                }
            }
        }
    }

    /**
     * Handle an incoming WebSocket message or control frame.
     *
     * With open_websocket_ping_frame and open_websocket_pong_frame enabled
     * in the Swoole server settings, ping/pong control frames are delivered
     * here instead of being handled automatically. This allows connection
     * activity tracking and control frame detection.
     */
    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        $lifecycle = static::$connections[$frame->fd] ?? null;

        if ($lifecycle === null) {
            return;
        }

        $lifecycle->run(function (ConnectionLifecycle $lifecycle) use ($frame, $server): void {
            $connection = $lifecycle->connection();

            if ($connection === null || ! $connection->isEstablished()) {
                return;
            }

            // Control frames — delegate to PusherServer::control() for protocol
            // parity (logging, activity tracking, control frame detection).
            // Auto-respond to pings with pong at the Swoole level.
            if (in_array($frame->opcode, [WEBSOCKET_OPCODE_PING, WEBSOCKET_OPCODE_PONG], true)) {
                $this->server->control($connection, $frame->opcode);

                if ($frame->opcode === WEBSOCKET_OPCODE_PING) {
                    $server->push($frame->fd, '', WEBSOCKET_OPCODE_PONG);
                }

                return;
            }

            // Enforce per-app message size limit before passing to the protocol server.
            // In Laravel Reverb, this is handled by Ratchet's MessageBuffer.
            if (strlen($frame->data) > $connection->app()->maxMessageSize()) {
                $server->push($frame->fd, 'Maximum message size exceeded');

                return;
            }

            $this->server->message($connection, $frame->data);
        });
    }

    /**
     * Handle a WebSocket connection close.
     */
    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        $lifecycle = static::takeConnection($fd);

        if ($lifecycle === null) {
            return;
        }

        $this->closeLifecycle($lifecycle);
    }

    /**
     * Get all active connections.
     *
     * @return array<int, ConnectionLifecycle>
     */
    public static function connections(): array
    {
        return static::$connections;
    }

    /**
     * Atomically take and remove a lifecycle from the registry.
     *
     * When an expected lifecycle is supplied, a replacement using the same file
     * descriptor is left untouched.
     */
    public static function takeConnection(int $fd, ?ConnectionLifecycle $expected = null): ?ConnectionLifecycle
    {
        $lifecycle = static::$connections[$fd] ?? null;

        if ($lifecycle === null || ($expected !== null && $lifecycle !== $expected)) {
            return null;
        }

        unset(static::$connections[$fd]);

        return $lifecycle;
    }

    /**
     * Run terminal protocol and transport cleanup for a lifecycle.
     */
    public function closeLifecycle(ConnectionLifecycle $lifecycle, ?callable $disconnect = null): void
    {
        $lifecycle->close(function (ConnectionLifecycle $lifecycle) use ($disconnect): void {
            $exception = null;
            $connection = $lifecycle->connection();

            if ($connection !== null) {
                try {
                    $this->server->close($connection);
                } catch (Throwable $throwable) {
                    $exception = $throwable;
                }
            }

            if ($disconnect !== null) {
                try {
                    $disconnect();
                } catch (Throwable $throwable) {
                    $exception ??= $throwable;
                }
            }

            if ($exception !== null) {
                throw $exception;
            }
        });
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$connections = [];
    }
}
