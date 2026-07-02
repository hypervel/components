<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Auth\Middleware\UseGuard;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class InteractsWithRouteMiddlewareTest extends TestCase
{
    public function testAssertRouteMiddlewareRunsBeforeUsesResolvedRuntimeMiddleware(): void
    {
        Route::get('/login', fn () => 'ok')
            ->middleware(['auth.guard:admin', 'guest'])
            ->name('login');

        $this->assertRouteMiddlewareRunsBefore(
            'login',
            UseGuard::class . ':admin',
            RedirectIfAuthenticated::class,
        );
    }

    public function testAssertRouteMiddlewareRunsBeforeUsesMiddlewarePriority(): void
    {
        Route::get('/user', fn () => 'ok')
            ->middleware(['auth', 'auth.guard:admin'])
            ->name('user');

        $this->assertRouteMiddlewareRunsBefore(
            'user',
            UseGuard::class . ':admin',
            Authenticate::class,
        );
    }
}
