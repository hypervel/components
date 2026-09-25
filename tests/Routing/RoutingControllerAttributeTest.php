<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RoutingControllerAttributeTest;

use Hypervel\Container\Container;
use Hypervel\Routing\Attributes\Controllers\Middleware;
use Hypervel\Routing\Controller as RoutingController;
use Hypervel\Routing\Controllers\HasMiddleware;
use Hypervel\Routing\Route;
use Hypervel\Tests\Routing\RoutingTestCase;
use Override;

class RoutingControllerAttributeTest extends RoutingTestCase
{
    public function testControllerMiddlewareAttributesAreInherited(): void
    {
        $route = new Route('GET', 'foo', ['uses' => InheritMiddlewareController::class . '@index']);
        $route->setContainer(new Container);

        $this->assertEquals(['auth', 'log'], $route->gatherMiddleware());
    }

    public function testControllerMiddlewareAttributesAreInheritedInDeclarationOrder(): void
    {
        $route = new Route('GET', 'foo', ['uses' => InheritMiddlewareDeclarationOrderController::class . '@index']);
        $route->setContainer(new Container);

        $this->assertEquals(['middleware1', 'middleware2', 'middleware3'], $route->gatherMiddleware());
    }

    public function testControllerMiddlewareMergesWithAttributeMiddleware(): void
    {
        $route = new Route('GET', 'foo', ['uses' => StaticMiddlewareController::class . '@index']);
        $route->setContainer(new Container);

        $this->assertEquals(['static-middleware', 'attribute-middleware-1', 'attribute-middleware-2'], $route->gatherMiddleware());

        $route = new Route('GET', 'bar', ['uses' => DynamicMiddlewareController::class . '@index']);
        $route->setContainer(new Container);

        $this->assertEquals(['dynamic-middleware', 'attribute-middleware-1', 'attribute-middleware-2'], $route->gatherMiddleware());
    }
}

abstract class Controller
{
}

#[Middleware('auth')]
abstract class BaseMiddlewareController extends Controller
{
}

#[Middleware('log')]
class InheritMiddlewareController extends BaseMiddlewareController
{
    /**
     * Handle the request.
     */
    public function index(): void
    {
    }
}

#[Middleware('middleware1')]
#[Middleware('middleware2')]
abstract class BaseMiddlewareDeclarationOrderController extends Controller
{
}

#[Middleware('middleware3')]
class InheritMiddlewareDeclarationOrderController extends BaseMiddlewareDeclarationOrderController
{
    /**
     * Handle the request.
     */
    public function index(): void
    {
    }
}

#[Middleware('attribute-middleware-1')]
class StaticMiddlewareController implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     */
    #[Override]
    public static function middleware(): array
    {
        return ['static-middleware'];
    }

    /**
     * Handle the request.
     */
    #[Middleware('attribute-middleware-2')]
    public function index(): void
    {
    }
}

#[Middleware('attribute-middleware-1')]
class DynamicMiddlewareController extends RoutingController
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('dynamic-middleware');
    }

    /**
     * Handle the request.
     */
    #[Middleware('attribute-middleware-2')]
    public function index(): void
    {
    }
}
