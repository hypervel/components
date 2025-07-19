<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\Contracts\Factory as AuthFactory;
use Hypervel\Auth\Contracts\Guard;
use Hypervel\Sanctum\Http\Middleware\CheckAbilities;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @coversNothing
 */
class CheckAbilitiesTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }

    public function testRequestIsPassedAlongIfAbilitiesArePresentOnToken(): void
    {
        // Create a user object with the required methods
        $user = new class implements \Hypervel\Auth\Contracts\Authenticatable {
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

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return 'password';
            }
        };

        $request = Mockery::mock(ServerRequestInterface::class);
        $response = Mockery::mock(ResponseInterface::class);

        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn($user);

        $authFactory = Mockery::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $result = $middleware->handle($request, function ($req) use ($response) {
            return $response;
        }, 'foo', 'bar');

        $this->assertSame($response, $result);
    }

    public function testExceptionIsThrownIfTokenDoesntHaveAbility(): void
    {
        $this->expectException(\Hypervel\Sanctum\Exceptions\MissingAbilityException::class);

        $user = new class implements \Hypervel\Auth\Contracts\Authenticatable {
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

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return 'password';
            }
        };

        $request = Mockery::mock(ServerRequestInterface::class);

        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn($user);

        $authFactory = Mockery::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoAuthenticatedUser(): void
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $request = Mockery::mock(ServerRequestInterface::class);

        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->once()->andReturn(null);

        $authFactory = Mockery::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoToken(): void
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $user = new class implements \Hypervel\Auth\Contracts\Authenticatable {
            public function currentAccessToken()
            {
                return null;
            }

            public function tokenCan(string $ability): bool
            {
                return false;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return 'password';
            }
        };

        $request = Mockery::mock(ServerRequestInterface::class);

        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn($user);

        $authFactory = Mockery::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);

        $middleware = new CheckAbilities($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }
}
