<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Contracts\HasAbilities;
use Hypervel\Sanctum\Contracts\HasApiTokens;
use Hypervel\Sanctum\Exceptions\MissingAbilityException;
use Hypervel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

class CheckForAnyAbilityTest extends TestCase
{
    public function testRequestIsPassedAlongIfAbilitiesArePresentOnToken(): void
    {
        $middleware = new CheckForAnyAbility;
        $request = new Request;
        $user = m::mock(HasApiTokens::class);
        $request->setUserResolver(fn (): HasApiTokens => $user);
        $user->expects('currentAccessToken')->andReturn(m::mock(HasAbilities::class));
        $user->expects('tokenCan')->with('foo')->andReturn(true);
        $user->allows('tokenCan')->with('bar')->andReturn(false);
        $expected = new Response('response');

        $response = $middleware->handle($request, function () use ($expected): Response {
            return $expected;
        }, 'foo', 'bar');

        $this->assertSame($expected, $response);
    }

    public function testExceptionIsThrownIfTokenDoesntHaveAbility(): void
    {
        $this->expectException(MissingAbilityException::class);

        $middleware = new CheckForAnyAbility;
        $request = new Request;
        $user = m::mock(HasApiTokens::class);
        $request->setUserResolver(fn (): HasApiTokens => $user);
        $user->expects('currentAccessToken')->andReturn(m::mock(HasAbilities::class));
        $user->expects('tokenCan')->with('foo')->andReturn(false);
        $user->expects('tokenCan')->with('bar')->andReturn(false);

        $middleware->handle($request, function (): Response {
            return new Response('response');
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoAuthenticatedUser(): void
    {
        $this->expectException(AuthenticationException::class);

        $middleware = new CheckForAnyAbility;
        $request = new Request;
        $request->setUserResolver(fn (): null => null);

        $middleware->handle($request, function (): Response {
            return new Response('response');
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoToken(): void
    {
        $this->expectException(AuthenticationException::class);

        $middleware = new CheckForAnyAbility;
        $request = new Request;
        $user = m::mock(HasApiTokens::class);
        $request->setUserResolver(fn (): HasApiTokens => $user);
        $user->expects('currentAccessToken')->andReturn(null);

        $middleware->handle($request, function (): Response {
            return new Response('response');
        }, 'foo', 'bar');
    }
}
