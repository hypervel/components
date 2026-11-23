<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\WebSocketServer\HandshakeHandler;
use Hypervel\WebSocketServer\Security;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;

class HandshakeHandlerTest extends TestCase
{
    public function testBuildsHandshakeWithoutImplicitlySelectingAProtocol(): void
    {
        $handler = new HandshakeHandler(new Container);
        $request = $this->request(WebSocketStub::class);
        $request->headers = new HeaderBag([
            Security::SEC_WEBSOCKET_KEY => 'dGhlIHNhbXBsZSBub25jZQ==',
            Security::SEC_WEBSOCKET_PROTOCOL => 'chat, superchat',
        ]);

        $response = $handler->handleHandshake($request);

        $this->assertSame(101, $response->getStatusCode());
        $this->assertSame('websocket', $response->headers->get('Upgrade'));
        $this->assertFalse($response->headers->has(Security::SEC_WEBSOCKET_PROTOCOL));
    }

    public function testMissingHandlerIsAServerConfigurationFailure(): void
    {
        $handler = new HandshakeHandler(new Container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('WebSocket handler not found.');

        $handler->handleHandshake($this->request('MissingWebSocketHandler'));
    }

    /**
     * Create a request with the given route controller.
     *
     * @param class-string|string $controller
     */
    protected function request(string $controller): Request
    {
        $route = new Route('GET', '/', ['uses' => $controller . '@onOpen']);
        $request = Request::create('/');
        $request->setRouteResolver(fn (): Route => $route);
        $request->headers = new HeaderBag([
            Security::SEC_WEBSOCKET_KEY => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        return $request;
    }
}
