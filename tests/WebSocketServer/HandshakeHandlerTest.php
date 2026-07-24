<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\WebSocketServer\HandshakeHandler;
use Hypervel\WebSocketServer\Security;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderBag;

class HandshakeHandlerTest extends TestCase
{
    public function testBuildsHandshakeWithoutImplicitlySelectingAProtocol(): void
    {
        $handler = new HandshakeHandler($this->container());
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
        $handler = new HandshakeHandler($this->container());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WebSocket handler not found.');

        $handler->handleHandshake($this->request('MissingWebSocketHandler'));
    }

    /**
     * Create the container used by the handshake handler.
     */
    protected function container(): Container
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with(Security::class)->andReturn(new Security);

        return $container;
    }

    /**
     * Create a request with the given route controller.
     *
     * @param class-string|string $controller
     */
    protected function request(string $controller): Request
    {
        $route = m::mock(Route::class);
        $route->shouldReceive('getControllerClass')->andReturn($controller);

        $request = m::mock(Request::class)->makePartial();
        $request->shouldReceive('route')->andReturn($route);
        $request->headers = new HeaderBag([
            Security::SEC_WEBSOCKET_KEY => 'dGhlIHNhbXBsZSBub25jZQ==',
        ]);

        return $request;
    }
}
