# WebSockets

- [Introduction](#introduction)
- [Server Configuration](#server-configuration)
- [Defining WebSocket Handlers](#defining-websocket-handlers)
    - [Connection Context](#connection-context)
- [Sending Messages](#sending-messages)
- [Subprotocols](#subprotocols)
- [Events](#events)

<a name="introduction"></a>
## Introduction

Hypervel's WebSocket Server package lets you handle native Swoole WebSocket connections using routes, middleware, service-container injection, and framework events.

For Laravel event broadcasting, Pusher-compatible clients, channels, presence, or multi-instance scaling, you should use [Hypervel Reverb](/docs/{{version}}/reverb). The lower-level WebSocket Server package is intended for applications that need to implement their own protocol.

<a name="server-configuration"></a>
## Server Configuration

To add a WebSocket listener, define another server in your application's `config/server.php` file:

```php
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use Hypervel\WebSocketServer\Server as WebSocketServer;

'servers' => [
    // Your application's HTTP server...

    [
        'name' => 'websocket',
        'type' => ServerInterface::SERVER_WEBSOCKET,
        'host' => env('WEBSOCKET_SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('WEBSOCKET_SERVER_PORT', 8080),
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [
            Event::ON_REQUEST => [Hypervel\HttpServer\Server::class, 'onRequest'],
            Event::ON_HANDSHAKE => [WebSocketServer::class, 'onHandshake'],
            Event::ON_MESSAGE => [WebSocketServer::class, 'onMessage'],
            Event::ON_CLOSE => [WebSocketServer::class, 'onClose'],
        ],
    ],
],
```

WebSocket handshakes are matched against your application's routes and run through their middleware. Register a class-based route for each WebSocket endpoint:

```php
use App\WebSockets\ChatSocket;
use Hypervel\Support\Facades\Route;

Route::get('/ws/chat', ChatSocket::class);
```

The route's controller class becomes the handler for the connection. Closure routes cannot be used as WebSocket handlers.

<a name="defining-websocket-handlers"></a>
## Defining WebSocket Handlers

A WebSocket handler may implement any of the `OnOpenInterface`, `OnMessageInterface`, and `OnCloseInterface` contracts it needs:

```php
<?php

namespace App\WebSockets;

use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Engine\WebSocket\Opcode;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\WebSocketServer\Context;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\HttpFoundation\Response;

class ChatSocket implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    /**
     * Handle a regular HTTP request to the WebSocket route.
     */
    public function __invoke(HttpRequest $request): Response
    {
        return new Response('Upgrade Required', 426, [
            'Upgrade' => 'websocket',
        ]);
    }

    /**
     * Handle a new WebSocket connection.
     */
    public function onOpen(WebSocketServer $server, SwooleRequest $request): void
    {
        Context::set('room', $request->get['room'] ?? 'lobby');

        $server->push($request->fd, 'Connected', Opcode::TEXT);
    }

    /**
     * Handle an incoming WebSocket message.
     */
    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        $room = Context::get('room');

        $server->push($frame->fd, "[{$room}] {$frame->data}", Opcode::TEXT);
    }

    /**
     * Handle a closed WebSocket connection.
     */
    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        // Release application resources owned by this connection...
    }
}
```

The handler's `__invoke` method handles ordinary HTTP requests because the route is also visible to your application's HTTP server. WebSocket handshakes run the route's middleware but use the handler's lifecycle methods instead of invoking the controller.

Handlers are resolved through the service container and normally live for the lifetime of a worker. Do not store connection-specific data on handler properties.

<a name="connection-context"></a>
### Connection Context

Use `Hypervel\WebSocketServer\Context` to store data for the current connection:

```php
Context::set('user_id', $user->id);

$userId = Context::get('user_id');

if (Context::has('user_id')) {
    Context::forget('user_id');
}
```

The `getOrSet` method stores a value only when the key does not exist, while `override` updates a value using a closure:

```php
$attempts = Context::getOrSet('attempts', 0);

Context::override('attempts', fn (?int $attempts): int => ($attempts ?? 0) + 1);
```

Context keys support dot notation. Hypervel releases all context for a connection after its close callback, including when the handler or an event listener throws.

<a name="sending-messages"></a>
## Sending Messages

The Swoole server passed to `onOpen` and `onMessage` may be used to reply to the current connection. To send to a file descriptor from another service, inject `Hypervel\WebSocketServer\Sender`:

```php
use Hypervel\Engine\WebSocket\Opcode;
use Hypervel\WebSocketServer\Sender;
use RuntimeException;

class ConnectionNotifier
{
    public function __construct(
        protected Sender $sender,
    ) {
    }

    public function send(int $fd, string $message): void
    {
        if (! $this->sender->push($fd, $message, Opcode::TEXT)) {
            throw new RuntimeException('Unable to send the WebSocket message.');
        }
    }
}
```

The `push`, `pushFrame`, and `disconnect` methods return whether the local native operation succeeded. In Swoole BASE mode, a non-local send returns whether every cross-worker pipe message was accepted; it does not confirm that another worker delivered the message to the client.

<a name="subprotocols"></a>
## Subprotocols

Hypervel does not automatically select a WebSocket subprotocol from the values offered by the client. If your endpoint supports a subprotocol, select exactly one supported value in route middleware:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SelectChatProtocol
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $offered = array_map(
            'trim',
            explode(',', $request->headers->get('Sec-WebSocket-Protocol', '')),
        );

        if (in_array('chat.v1', $offered, true)) {
            $response->headers->set('Sec-WebSocket-Protocol', 'chat.v1');
        }

        return $response;
    }
}
```

Attach the middleware to the WebSocket route in the usual way:

```php
Route::get('/ws/chat', ChatSocket::class)
    ->middleware(SelectChatProtocol::class);
```

<a name="events"></a>
## Events

Hypervel dispatches the following events for custom WebSocket connections:

- `Hypervel\WebSocketServer\Events\ConnectionOpened` provides the file descriptor, native request, and server name.
- `Hypervel\WebSocketServer\Events\MessageReceived` provides the file descriptor, native frame, and server name.
- `Hypervel\WebSocketServer\Events\ConnectionClosed` provides the file descriptor, reactor ID, and server name.

You may listen for these events using Hypervel's normal [event listeners](/docs/{{version}}/events#registering-events-and-listeners).
