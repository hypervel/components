<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Auth\Middleware\RequirePassword;
use Hypervel\Auth\Middleware\UseGuard;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;

class PasskeysRouteTest extends TestCase
{
    #[WithConfig('passkeys.guard', 'admin')]
    public function testGuardConfigAddsGuardSelectionMiddlewareBeforeGuestMiddlewareAtRuntime(): void
    {
        $this->assertRouteMiddlewareRunsBefore(
            'passkey.login',
            UseGuard::class . ':admin',
            RedirectIfAuthenticated::class,
        );
    }

    #[WithConfig('passkeys.guard', 'admin')]
    public function testGuardConfigRunsBeforeStandaloneAuthMiddlewareAtRuntime(): void
    {
        $this->assertRouteMiddlewareRunsBefore(
            'passkey.confirm-options',
            UseGuard::class . ':admin',
            Authenticate::class,
        );
    }

    #[WithConfig('passkeys.guard', 'admin')]
    public function testGuardConfigRunsBeforePasswordConfirmationMiddlewareAtRuntime(): void
    {
        $this->assertRouteMiddlewareRunsBefore(
            'passkey.registration-options',
            UseGuard::class . ':admin',
            RequirePassword::class,
        );
    }

    public function testNullGuardConfigDoesNotAddGuardSelectionMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('passkey.login');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertNotContains('auth.guard:admin', $middleware);
        $this->assertContains('guest', $middleware);
    }
}
