<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Routing\Attributes\Controllers\WithoutMiddleware;
use Hypervel\Support\Facades\Route;

class WithoutMiddlewareAttributeTest extends RoutingTestCase
{
    public function testAttributeWithoutMiddlewareIsRespected(): void
    {
        $route = Route::get('/', [WithoutMiddlewareAttributeController::class, 'index']);
        $this->assertSame([
            'all',
            'only-index',
            'also-index',
        ], $route->excludedMiddleware());

        $route = Route::get('/', [WithoutMiddlewareAttributeController::class, 'show'])->withoutMiddleware('merged');
        $this->assertSame([
            'merged',
            'all',
            'except-index',
        ], $route->excludedMiddleware());

        $route = Route::get('/', [ChildWithoutMiddlewareAttributeController::class, 'index']);
        $this->assertSame([
            'all',
            'only-index',
            'also-index',
        ], $route->excludedMiddleware());

        $route = Route::get('/', [ChildWithoutMiddlewareAttributeController::class, 'show'])->withoutMiddleware('merged');
        $this->assertSame([
            'merged',
            'all',
            'except-index',
        ], $route->excludedMiddleware());
    }

    public function testAttributePreservesFalsyMiddlewareNames(): void
    {
        $route = Route::get('/', [FalsyWithoutMiddlewareAttributeController::class, 'index']);

        $this->assertSame(['0', ''], $route->excludedControllerMiddleware());
    }
}

#[WithoutMiddleware('all')]
#[WithoutMiddleware('only-index', only: ['index'])]
#[WithoutMiddleware('except-index', except: ['index'])]
class WithoutMiddlewareAttributeController
{
    #[WithoutMiddleware('also-index')]
    public function index(): void
    {
        // ...
    }

    public function show(): void
    {
        // ...
    }
}

class ChildWithoutMiddlewareAttributeController extends WithoutMiddlewareAttributeController
{
}

#[WithoutMiddleware('0')]
#[WithoutMiddleware('')]
class FalsyWithoutMiddlewareAttributeController
{
    public function index(): void
    {
        // ...
    }
}
