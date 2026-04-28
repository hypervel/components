<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\WebSocketServer\Exceptions\WebSocketHandShakeException;
use Symfony\Component\HttpFoundation\Response;

class HandshakeHandler
{
    public function __construct(
        protected Container $container,
    ) {
    }

    /**
     * Build the WebSocket handshake response for a matched route.
     *
     * Validates that the matched route's controller class exists (it will be
     * used as the WS handler for subsequent onMessage/onClose callbacks),
     * then builds the 101 Switching Protocols response with the required
     * WebSocket headers.
     */
    public function handleHandshake(Request $request): Response
    {
        $route = $request->route();
        $controller = $route->getControllerClass();

        if (! $controller || ! class_exists($controller)) {
            throw new WebSocketHandShakeException('WebSocket handler not found.');
        }

        $security = $this->container->make(Security::class);

        $key = $request->headers->get(Security::SEC_WEBSOCKET_KEY);

        $headers = $security->handshakeHeaders($key);

        if ($wsProtocol = $request->headers->get(Security::SEC_WEBSOCKET_PROTOCOL)) {
            $headers[Security::SEC_WEBSOCKET_PROTOCOL] = $wsProtocol;
        }

        return new Response('', 101, $headers);
    }
}
