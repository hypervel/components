<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;

class PasskeysRouteTest extends TestCase
{
    #[WithConfig('passkeys.guard', 'admin')]
    public function testGuardConfigAddsGuardSelectionMiddlewareBeforeRouteMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('passkey.login');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth.guard:admin', $middleware);
        $this->assertLessThan(
            array_search('guest', $middleware, true),
            array_search('auth.guard:admin', $middleware, true),
        );
    }

    #[WithConfig('passkeys.guard', 'admin')]
    public function testGuardConfigRunsBeforeStandaloneAuthRoutes(): void
    {
        $route = Route::getRoutes()->getByName('passkey.confirm-options');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth.guard:admin', $middleware);
        $this->assertLessThan(
            array_search('auth', $middleware, true),
            array_search('auth.guard:admin', $middleware, true),
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
