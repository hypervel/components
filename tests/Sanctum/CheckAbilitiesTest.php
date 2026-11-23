<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Contracts\HasAbilities;
use Hypervel\Sanctum\Contracts\HasApiTokens;
use Hypervel\Sanctum\Exceptions\MissingAbilityException;
use Hypervel\Sanctum\Http\Middleware\CheckAbilities;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\HttpFoundation\Response;

class CheckAbilitiesTest extends TestCase
{
    use VerifiesDoubles;

    public function testRequestIsPassedAlongIfAbilitiesArePresentOnToken(): void
    {
        $middleware = new CheckAbilities;
        $request = new Request;
        $user = Double::for(HasApiTokens::class)->strict();
        $request->setUserResolver(fn (): HasApiTokens => $user);
        $user->expects('currentAccessToken')->returns(Double::for(HasAbilities::class)->strict());
        $user->expects('tokenCan')->with('foo')->returns(true);
        $user->expects('tokenCan')->with('bar')->returns(true);
        $expected = new Response('response');

        $response = $middleware->handle($request, function () use ($expected): Response {
            return $expected;
        }, 'foo', 'bar');

        $this->assertSame($expected, $response);
    }

    public function testExceptionIsThrownIfTokenDoesntHaveAbility(): void
    {
        $this->expectException(MissingAbilityException::class);

        $middleware = new CheckAbilities;
        $request = new Request;
        $user = Double::for(HasApiTokens::class)->strict();
        $request->setUserResolver(fn (): HasApiTokens => $user);
        $user->expects('currentAccessToken')->returns(Double::for(HasAbilities::class)->strict());
        $user->expects('tokenCan')->with('foo')->returns(false);

        $middleware->handle($request, function (): Response {
            return new Response('response');
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoAuthenticatedUser(): void
    {
        $this->expectException(AuthenticationException::class);

        $middleware = new CheckAbilities;
        $request = new Request;
        $request->setUserResolver(fn (): null => null);

        $middleware->handle($request, function (): Response {
            return new Response('response');
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoToken(): void
    {
        $this->expectException(AuthenticationException::class);

        $middleware = new CheckAbilities;
        $request = new Request;
        $user = Double::for(HasApiTokens::class)->strict();
        $request->setUserResolver(fn (): HasApiTokens => $user);
        $user->expects('currentAccessToken')->returns(null);

        $middleware->handle($request, function (): Response {
            return new Response('response');
        }, 'foo', 'bar');
    }
}
