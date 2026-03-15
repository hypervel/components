<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use FastRoute\Dispatcher;
use Hypervel\Http\DispatchedRoute;
use Hypervel\Router\RouteHandler;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DispatchedRouteTest extends TestCase
{
    public function testGetHandler(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertSame($handler, $dispatched->getHandler());
    }

    public function testIsClosure(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $handler->method('isClosure')->willReturn(true);

        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertTrue($dispatched->isClosure());
    }

    public function testIsControllerAction(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $handler->method('isControllerAction')->willReturn(true);

        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertTrue($dispatched->isControllerAction());
    }

    public function testGetCallback(): void
    {
        $closure = fn () => 'response';
        $handler = $this->createMock(RouteHandler::class);
        $handler->method('getCallback')->willReturn($closure);

        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertSame($closure, $dispatched->getCallback());
    }

    public function testParametersReturnsEmptyArrayWhenNoParameters(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertSame([], $dispatched->parameters());
    }

    public function testParametersReturnsParametersArray(): void
    {
        $params = ['id' => '123', 'slug' => 'test-slug'];
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, $params]);

        $this->assertSame($params, $dispatched->parameters());
    }

    public function testParameterReturnsSpecificParameter(): void
    {
        $params = ['id' => '123', 'slug' => 'test-slug'];
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, $params]);

        $this->assertSame('123', $dispatched->parameter('id'));
        $this->assertSame('test-slug', $dispatched->parameter('slug'));
    }

    public function testParameterReturnsDefaultWhenParameterNotExists(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertNull($dispatched->parameter('nonexistent'));
        $this->assertSame('default', $dispatched->parameter('nonexistent', 'default'));
    }

    public function testHasParametersWithoutParameters(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertFalse($dispatched->hasParameters());
    }

    public function testHasParametersWithParameters(): void
    {
        $params = ['id' => '123'];
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, $params]);

        $this->assertTrue($dispatched->hasParameters());
    }

    public function testHasParameter(): void
    {
        $params = ['id' => '123', 'slug' => 'test-slug'];
        $handler = $this->createMock(RouteHandler::class);
        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, $params]);

        $this->assertTrue($dispatched->hasParameter('id'));
        $this->assertTrue($dispatched->hasParameter('slug'));
        $this->assertFalse($dispatched->hasParameter('foo'));
    }

    public function testGetName(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $handler->method('getName')->willReturn('test.route');

        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertSame('test.route', $dispatched->getName());
    }

    public function testGetMiddleware(): void
    {
        $middleware = ['auth', 'throttle:60,1'];
        $handler = $this->createMock(RouteHandler::class);
        $handler->method('getMiddleware')->willReturn($middleware);

        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertSame($middleware, $dispatched->getMiddleware());
    }

    public function testGetControllerClass(): void
    {
        $handler = $this->createMock(RouteHandler::class);
        $handler->method('getControllerClass')->willReturn('TestController');

        $dispatched = new DispatchedRoute([Dispatcher::FOUND, $handler, []]);

        $this->assertSame('TestController', $dispatched->getControllerClass());
    }
}
