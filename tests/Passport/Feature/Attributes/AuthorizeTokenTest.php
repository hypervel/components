<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature\Attributes;

use Hypervel\Passport\Attributes\AuthorizeToken;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class AuthorizeTokenTest extends TestCase
{
    public function testItAppliesCheckTokenMiddleware(): void
    {
        $route = Route::get('/test', [AuthorizeTokenControllerTest::class, 'index']);

        $this->assertSame([
            'Hypervel\Passport\Http\Middleware\CheckToken:all',
            'Hypervel\Passport\Http\Middleware\CheckToken:only-index',
            'Hypervel\Passport\Http\Middleware\CheckToken:also-index',
        ], $route->controllerMiddleware());

        $route = Route::get('/test', [AuthorizeTokenControllerTest::class, 'show']);

        $this->assertSame([
            'Hypervel\Passport\Http\Middleware\CheckToken:all',
            'Hypervel\Passport\Http\Middleware\CheckToken:except-index',
        ], $route->controllerMiddleware());
    }

    public function testItAppliesCheckTokenForAnyScopeMiddleware(): void
    {
        $route = Route::get('/test', [AuthorizeTokenControllerTest::class, 'store']);

        $this->assertSame([
            'Hypervel\Passport\Http\Middleware\CheckToken:all',
            'Hypervel\Passport\Http\Middleware\CheckToken:except-index',
            'Hypervel\Passport\Http\Middleware\CheckTokenForAnyScope:only-store,something-else',
        ], $route->controllerMiddleware());
    }
}

#[AuthorizeToken('all')]
#[AuthorizeToken('only-index', only: ['index'])]
#[AuthorizeToken('except-index', except: ['index'])]
class AuthorizeTokenControllerTest
{
    #[AuthorizeToken('also-index')]
    public function index(): void
    {
    }

    public function show(): void
    {
    }

    #[AuthorizeToken(['only-store', 'something-else'], anyScope: true)]
    public function store(): void
    {
    }
}
