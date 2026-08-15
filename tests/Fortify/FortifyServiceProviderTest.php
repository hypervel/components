<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Closure;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use Hypervel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Hypervel\Fortify\Contracts\TwoFactorEnabledResponse as TwoFactorEnabledResponseContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\FortifyServiceProvider;
use Hypervel\Fortify\Http\Responses\TwoFactorDisabledResponse;
use Hypervel\Fortify\Http\Responses\TwoFactorEnabledResponse;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Fortify\Fixtures\FixedClock;
use InvalidArgumentException;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;
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

    public function testTwoFactorAuthenticationProviderUsesFrameworkClock(): void
    {
        $timestamp = 946684800;
        $clock = new FixedClock($timestamp);
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $code = TOTP::createFromSecret($secret, $clock)->at($timestamp);

        $this->app->instance(ClockInterface::class, $clock);
        $this->app->forgetInstance(TwoFactorAuthenticationProviderContract::class);

        $provider = $this->app->make(TwoFactorAuthenticationProviderContract::class);

        $this->assertTrue($provider->verify($secret, $code));
    }

    public function testPasskeyBridgeRequiresTheConfiguredTimeout(): void
    {
        config(['fortify.passkeys' => [
            'relying_party_id' => null,
            'allowed_origins' => [],
            'user_handle_secret' => null,
        ]]);

        $provider = $this->app->getProvider(FortifyServiceProvider::class);
        $method = new ReflectionMethod($provider, 'configurePasskeys');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [fortify.passkeys.timeout]');

        $method->invoke($provider);
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
