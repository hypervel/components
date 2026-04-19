<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\HasMiddlewareTest;

use Hypervel\Routing\Controllers\HasMiddleware;
use Hypervel\Routing\Controllers\Middleware;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;

class HasMiddlewareTest extends RoutingTestCase
{
    public function testHasMiddlewareIsRespected()
    {
        $route = Route::get('/', [HasMiddlewareTestController::class, 'index']);
        $this->assertEquals($route->controllerMiddleware(), ['all', 'only-index']);

        $route = Route::get('/', [HasMiddlewareTestController::class, 'show']);
        $this->assertEquals($route->controllerMiddleware(), ['all', 'except-index']);
    }
}

class HasMiddlewareTestController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('all'),
            (new Middleware('only-index'))->only('index'),
            (new Middleware('except-index'))->except('index'),
        ];
    }

    public function index()
    {
    }

    public function show()
    {
    }
}
