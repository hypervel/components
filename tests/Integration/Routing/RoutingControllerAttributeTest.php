<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\RoutingControllerAttributeTest;

use Hypervel\Routing\Attributes\Controllers\Middleware;
use Hypervel\Routing\Controller as RoutingController;
use Hypervel\Routing\Controllers\HasMiddleware;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;
use Override;

class RoutingControllerAttributeTest extends RoutingTestCase
{
    public function testControllerMiddlewareAttributesAreInherited(): void
    {
        $route = Route::get('foo', [InheritMiddlewareController::class, 'index']);

        $this->assertEquals(['auth', 'log'], $route->controllerMiddleware());
    }

    public function testControllerMiddlewareAttributesAreInheritedInDeclarationOrder(): void
    {
        $route = Route::get('foo', [InheritMiddlewareDeclarationOrderController::class, 'index']);

        $this->assertEquals(['middleware1', 'middleware2', 'middleware3'], $route->controllerMiddleware());
    }

    public function testControllerMiddlewareMergesWithAttributeMiddleware(): void
    {
        $route = Route::get('foo', [StaticMiddlewareController::class, 'index']);

        $this->assertEquals(['static-middleware', 'attribute-middleware-1', 'attribute-middleware-2'], $route->controllerMiddleware());

        $route = Route::get('bar', [DynamicMiddlewareController::class, 'index']);

        $this->assertEquals(['dynamic-middleware', 'attribute-middleware-1', 'attribute-middleware-2'], $route->controllerMiddleware());
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
    public function index(): void
    {
    }
}

#[Middleware('attribute-middleware-1')]
class StaticMiddlewareController implements HasMiddleware
{
    #[Override]
    public static function middleware(): array
    {
        return ['static-middleware'];
    }

    #[Middleware('attribute-middleware-2')]
    public function index(): void
    {
    }
}

#[Middleware('attribute-middleware-1')]
class DynamicMiddlewareController extends RoutingController
{
    public function __construct()
    {
        $this->middleware('dynamic-middleware');
    }

    #[Middleware('attribute-middleware-2')]
    public function index(): void
    {
    }
}
