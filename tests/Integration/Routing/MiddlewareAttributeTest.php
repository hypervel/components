<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\MiddlewareAttributeTest;

use Hypervel\Routing\Attributes\Controllers\Middleware;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;

/**
 * @internal
 * @coversNothing
 */
class MiddlewareAttributeTest extends RoutingTestCase
{
    public function testAttributeMiddlewareIsRespected(): void
    {
        $route = Route::get('/', [MiddlewareAttributeController::class, 'index']);
        $this->assertEquals([
            'all',
            'only-index',
            'also-index',
        ], $route->controllerMiddleware());

        $route = Route::get('/', [MiddlewareAttributeController::class, 'show']);
        $this->assertEquals([
            'all',
            'except-index',
        ], $route->controllerMiddleware());
    }
}

#[Middleware('all')]
#[Middleware('only-index', only: ['index'])]
#[Middleware('except-index', except: ['index'])]
class MiddlewareAttributeController
{
    #[Middleware('also-index')]
    public function index(): void
    {
        // ...
    }

    public function show(): void
    {
        // ...
    }
}
