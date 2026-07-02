<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Auth\Middleware\RedirectIfAuthenticated;
use Hypervel\Auth\Middleware\UseGuard;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Fortify\Fixtures\Admin;

class FortifyRouteTest extends TestCase
{
    #[WithConfig('fortify.guard', 'admin')]
    public function testGuardConfigRunsBeforeLoginGuestMiddlewareAtRuntime(): void
    {
        $this->assertRouteMiddlewareRunsBefore(
            'login.store',
            UseGuard::class . ':admin',
            RedirectIfAuthenticated::class,
        );
    }

    #[WithConfig('fortify.guard', 'admin')]
    #[WithConfig('passkeys.guard', 'web')]
    public function testFortifyPasskeyRoutesUseFortifyGuardInsteadOfStandalonePasskeysGuard(): void
    {
        $middleware = $this->resolvedMiddlewareForRoute('passkey.login');

        $this->assertContains(UseGuard::class . ':admin', $middleware);
        $this->assertNotContains(UseGuard::class . ':web', $middleware);
        $this->assertSame('web', config('passkeys.guard'));
    }

    public function testPasskeyDeletionUsesPasswordConfirmationWithoutThrottle(): void
    {
        $route = Route::getRoutes()->getByName('passkey.destroy');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('password.confirm', $middleware);
        $this->assertStringNotContainsString('throttle:', implode('|', $middleware));
    }

    public function testTwoFactorChallengeIsThrottledByDefault(): void
    {
        $route = Route::getRoutes()->getByName('two-factor.login.store');

        $this->assertNotNull($route);

        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }

    #[WithConfig('fortify.limiters.two-factor', '10,1')]
    public function testTwoFactorChallengeThrottleCanBeCustomized(): void
    {
        $route = Route::getRoutes()->getByName('two-factor.login.store');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('throttle:10,1', $middleware);
        $this->assertNotContains('throttle:5,1', $middleware);
    }

    public function testGuardSelectionMiddlewareRunsBeforeBareAuthMiddleware(): void
    {
        $admin = new Admin([
            'email' => 'admin@example.test',
        ]);
        $admin->setAttribute($admin->getKeyName(), 1);

        Auth::guard('admin')->setUser($admin);

        Route::get('/guard-priority', static fn (Request $request): string => $request->user()::class)
            ->middleware(['auth.guard:admin', 'auth']);

        $this->get('/guard-priority')->assertOk()->assertSee(Admin::class);
    }

    #[WithConfig('fortify.guard', 'admin')]
    public function testPinnedGuardAuthenticatesFortifyAuthRoutesBeforeControllerRuns(): void
    {
        $admin = new Admin([
            'email' => 'admin@example.test',
        ]);
        $admin->setAttribute($admin->getKeyName(), 1);

        Auth::guard('admin')->setUser($admin);

        $this->post('/logout')->assertRedirect('/');
    }

    public function testNullGuardConfigDoesNotAddGuardSelectionMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('login.store');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertNotContains('auth.guard:admin', $middleware);
        $this->assertContains('guest', $middleware);
    }
}
