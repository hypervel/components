<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Reverb\Protocols\Pusher\Server as PusherServer;
use Hypervel\WebSocketServer\Sender;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;

class WebSocketHandler implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    /**
     * Active connections mapped by file descriptor.
     *
     * @var array<int, \Hypervel\Reverb\Connection>
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

        try {
            $application = $this->applications->findByKey($appKey);
        } catch (InvalidApplication) {
            $server->push(
                $request->fd,
                '{"event":"pusher:error","data":"{\"code\":4001,\"message\":\"Application does not exist\"}"}'
            );
            $server->disconnect($request->fd);

            return;
        }

        $wsConnection = new Connection(
            $this->container->make(Sender::class),
            $request->fd,
        );

        $reverbConnection = new \Hypervel\Reverb\Connection(
            $wsConnection,
            $application,
            $httpRequest->headers->get('Origin'),
        );

        static::$connections[$request->fd] = $reverbConnection;

        $this->server->open($reverbConnection);
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
        $connection = static::$connections[$frame->fd] ?? null;

        if (! $connection) {
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
    }

    /**
     * Handle a WebSocket connection close.
     */
    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        $connection = static::$connections[$fd] ?? null;

        if (! $connection) {
            return;
        }

        $this->server->close($connection);
        unset(static::$connections[$fd]);
    }

    /**
     * Get all active connections.
     *
     * @return array<int, \Hypervel\Reverb\Connection>
     */
    public static function connections(): array
    {
        return static::$connections;
    }

    /**
     * Flush all stored connections.
     */
    public static function flushState(): void
    {
        static::$connections = [];
    }
}
