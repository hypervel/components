<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Http\Request;
use Hypervel\Sanctum\Http\Middleware\CheckAbilities;
use Hypervel\Tests\Sanctum\Fixtures\DummyAuthenticatable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 * @coversNothing
 */
class CheckAbilitiesTest extends TestCase
{
    public function testRequestIsPassedAlongIfAbilitiesArePresentOnToken()
    {
        $user = new class extends DummyAuthenticatable {
            private $token;

            public function __construct()
            {
                $this->token = new class {};
            }

            public function currentAccessToken()
            {
                return $this->token;
            }

            public function tokenCan(string $ability): bool
            {
                return in_array($ability, ['foo', 'bar']);
            }
        };

        $request = Request::create('http://example.com');
        $response = new Response();

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn($user);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $result = $middleware->handle($request, function ($req) use ($response) {
            return $response;
        }, 'foo', 'bar');

        $this->assertSame($response, $result);
    }

    public function testExceptionIsThrownIfTokenDoesntHaveAbility()
    {
        $this->expectException(\Hypervel\Sanctum\Exceptions\MissingAbilityException::class);

        $user = new class extends DummyAuthenticatable {
            private $token;

            public function __construct()
            {
                $this->token = new class {};
            }

            public function currentAccessToken()
            {
                return $this->token;
            }

            public function tokenCan(string $ability): bool
            {
                return false;
            }
        };

        $request = Request::create('http://example.com');

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn($user);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoAuthenticatedUser()
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $request = Request::create('http://example.com');

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')->once()->andReturn(null);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoToken()
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $user = new class extends DummyAuthenticatable {
            public function currentAccessToken()
            {
                return null;
            }

            public function tokenCan(string $ability): bool
            {
                return false;
            }
        };

        $request = Request::create('http://example.com');

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn($user);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }
}
