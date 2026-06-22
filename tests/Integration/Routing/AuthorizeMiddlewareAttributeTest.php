<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\AuthorizeMiddlewareAttributeTest;

use Hypervel\Auth\Middleware\Authorize as AuthorizeMiddleware;
use Hypervel\Routing\Attributes\Controllers\Authorize;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;

class AuthorizeMiddlewareAttributeTest extends RoutingTestCase
{
    public function testAttributeIsRespected(): void
    {
        $route = Route::get('/', [AuthorizeMiddlewareAttributeController::class, 'index']);
        $this->assertEquals([
            AuthorizeMiddleware::class . ':all',
            AuthorizeMiddleware::class . ':only-index,a',
            AuthorizeMiddleware::class . ':also-index',
        ], $route->controllerMiddleware());

        $route = Route::get('/', [AuthorizeMiddlewareAttributeController::class, 'show']);
        $this->assertEquals([
            AuthorizeMiddleware::class . ':all',
            AuthorizeMiddleware::class . ':except-index,a,b',
        ], $route->controllerMiddleware());
    }

    public function testAttributeAcceptsEnumAbilities(): void
    {
        $route = Route::get('/', [AuthorizeMiddlewareAttributeControllerWithEnumAbility::class, 'index']);

        $this->assertEquals([
            AuthorizeMiddleware::class . ':update,post',
        ], $route->controllerMiddleware());
    }
}

#[Authorize('all')]
#[Authorize('only-index', 'a', only: ['index'])]
#[Authorize('except-index', ['a', 'b'], except: ['index'])]
class AuthorizeMiddlewareAttributeController
{
    #[Authorize('also-index')]
    public function index(): void
    {
        // ...
    }

    public function show(): void
    {
        // ...
    }
}

class AuthorizeMiddlewareAttributeControllerWithEnumAbility
{
    #[Authorize(AuthorizeMiddlewareAttributeAbility::Update, 'post')]
    public function index(): void
    {
        // ...
    }
}

enum AuthorizeMiddlewareAttributeAbility: string
{
    case Update = 'update';
}
