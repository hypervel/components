<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Closure;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Hypervel\Fortify\Contracts\TwoFactorEnabledResponse as TwoFactorEnabledResponseContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\Http\Responses\TwoFactorDisabledResponse;
use Hypervel\Fortify\Http\Responses\TwoFactorEnabledResponse;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProviderTest extends TestCase
{
    public function testViewsCanBeCustomized(): void
    {
        Fortify::loginView(function () {
            return 'foo';
        });

        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSame('foo', $response->content());
    }

    public function testCustomizedViewsCanReturnTheirOwnResponsable(): void
    {
        Fortify::loginView(function () {
            return new class implements Responsable {
                public function toResponse(Request $request): Response
                {
                    return new JsonResponse(['foo' => 'bar']);
                }
            };
        });

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertExactJson(['foo' => 'bar']);
    }

    public function testTwoFactorResponseBindingsUseMatchingContracts(): void
    {
        $this->assertInstanceOf(
            TwoFactorEnabledResponse::class,
            $this->app->make(TwoFactorEnabledResponseContract::class)
        );

        $this->assertInstanceOf(
            TwoFactorDisabledResponse::class,
            $this->app->make(TwoFactorDisabledResponseContract::class)
        );
    }

    #[DefineEnvironment('withTwoFactorAuthentication')]
    public function testRedirectIfTwoFactorAuthenticatableIsResolvedFreshAfterFlushingScopedInstances(): void
    {
        $instanceA = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);
        $instanceB = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->assertSame($instanceA, $instanceB);

        $this->app->forgetScopedInstances();

        $instanceC = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->assertNotSame($instanceA, $instanceC);
    }

    #[DefineEnvironment('withTwoFactorAuthentication')]
    public function testRedirectIfTwoFactorAuthenticatableDoesNotCacheGuard(): void
    {
        $instance = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);
        $reflection = new ReflectionClass($instance);

        $this->assertFalse($reflection->hasProperty('guard'));
    }

    #[DefineEnvironment('withTwoFactorAuthentication')]
    public function testCustomRedirectIfTwoFactorAuthenticatableIsResolvedFreshAfterFlushingScopedInstances(): void
    {
        Fortify::redirectUserForTwoFactorAuthenticationUsing(TestRedirectIfTwoFactorAuthenticatable::class);

        $instanceA = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->app->forgetScopedInstances();

        $instanceB = $this->app->make(RedirectsIfTwoFactorAuthenticatable::class);

        $this->assertNotSame($instanceA, $instanceB);
    }
}

class TestRedirectIfTwoFactorAuthenticatable implements RedirectsIfTwoFactorAuthenticatable
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
