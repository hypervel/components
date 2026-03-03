<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router;

use Hypervel\Router\RouteHandler;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class RouteHandlerTest extends TestCase
{
    public function testGetCallbackReturnsCallback(): void
    {
        $callback = fn () => 'test';
        $handler = new RouteHandler($callback, '/test');

        $this->assertSame($callback, $handler->getCallback());
    }

    public function testIsClosureReturnsTrueForClosure(): void
    {
        $callback = fn () => 'test';
        $handler = new RouteHandler($callback, '/test');

        $this->assertTrue($handler->isClosure());
    }

    public function testIsClosureReturnsFalseForStringCallback(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertFalse($handler->isClosure());
    }

    public function testIsClosureReturnsFalseForArrayCallback(): void
    {
        $handler = new RouteHandler(['TestController', 'index'], '/test');

        $this->assertFalse($handler->isClosure());
    }

    public function testIsControllerActionReturnsFalseForClosure(): void
    {
        $callback = fn () => 'test';
        $handler = new RouteHandler($callback, '/test');

        $this->assertFalse($handler->isControllerAction());
    }

    public function testIsControllerActionReturnsTrueForStringCallback(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertTrue($handler->isControllerAction());
    }

    public function testIsControllerActionReturnsTrueForArrayCallback(): void
    {
        $handler = new RouteHandler(['TestController', 'index'], '/test');

        $this->assertTrue($handler->isControllerAction());
    }

    public function testGetRouteReturnsRoute(): void
    {
        $handler = new RouteHandler('TestController@index', '/test/route');

        $this->assertSame('/test/route', $handler->getRoute());
    }

    public function testGetOptionsReturnsOptions(): void
    {
        $options = ['middleware' => 'auth', 'as' => 'test.route'];
        $handler = new RouteHandler('TestController@index', '/test', $options);

        $this->assertSame($options, $handler->getOptions());
    }

    public function testGetOptionsReturnsEmptyArrayWhenNoOptions(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertSame([], $handler->getOptions());
    }

    public function testGetNameReturnsNameFromOptions(): void
    {
        $options = ['as' => 'test.route'];
        $handler = new RouteHandler('TestController@index', '/test', $options);

        $this->assertSame('test.route', $handler->getName());
    }

    public function testGetNameReturnsNullWhenNoName(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertNull($handler->getName());
    }

    public function testGetMiddlewareReturnsMiddlewareFromOptions(): void
    {
        $options = ['middleware' => ['auth', 'throttle']];
        $handler = new RouteHandler('TestController@index', '/test', $options);

        $this->assertSame(['auth', 'throttle'], $handler->getMiddleware());
    }

    public function testGetMiddlewareReturnsEmptyArrayWhenNoMiddleware(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertSame([], $handler->getMiddleware());
    }

    public function testGetControllerClassReturnsControllerForStringCallback(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertSame('TestController', $handler->getControllerClass());
    }

    public function testGetControllerClassReturnsControllerForArrayCallback(): void
    {
        $handler = new RouteHandler(['TestController', 'index'], '/test');

        $this->assertSame('TestController', $handler->getControllerClass());
    }

    public function testGetControllerClassReturnsNullForClosure(): void
    {
        $callback = fn () => 'test';
        $handler = new RouteHandler($callback, '/test');

        $this->assertNull($handler->getControllerClass());
    }

    public function testGetControllerCallbackParsesStringWithAtSymbol(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $this->assertSame(['TestController', 'index'], $handler->getControllerCallback());
    }

    public function testGetControllerCallbackParsesStringWithDoubleColon(): void
    {
        $handler = new RouteHandler('TestController::index', '/test');

        $this->assertSame(['TestController', 'index'], $handler->getControllerCallback());
    }

    public function testGetControllerCallbackReturnsInvokeForPlainString(): void
    {
        $handler = new RouteHandler('TestController', '/test');

        $this->assertSame(['TestController', '__invoke'], $handler->getControllerCallback());
    }

    public function testGetControllerCallbackReturnsArrayForArrayCallback(): void
    {
        $handler = new RouteHandler(['TestController', 'index'], '/test');

        $this->assertSame(['TestController', 'index'], $handler->getControllerCallback());
    }

    public function testGetControllerCallbackCachesResult(): void
    {
        $handler = new RouteHandler('TestController@index', '/test');

        $first = $handler->getControllerCallback();
        $second = $handler->getControllerCallback();

        $this->assertSame($first, $second);
    }

    public function testParseControllerCallbackThrowsExceptionForInvalidCallback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Route handler doesn't exist.");

        $handler = new RouteHandler(123, '/test');
        $handler->getControllerCallback();
    }

    public function testParseControllerCallbackThrowsExceptionForIncompleteArrayCallback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Route handler doesn't exist.");

        $handler = new RouteHandler(['TestController'], '/test');
        $handler->getControllerCallback();
    }

    public function testParseControllerCallbackThrowsExceptionForEmptyArrayCallback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Route handler doesn't exist.");

        $handler = new RouteHandler([], '/test');
        $handler->getControllerCallback();
    }
}
