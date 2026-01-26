<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @coversNothing
 */
class CheckForAnyAbilityTest extends TestCase
{
    /**
     * Test request is passed along if any abilities are present on token.
     */
    public function testRequestIsPassedAlongIfAbilitiesArePresentOnToken(): void
    {
        // Create a user object with the required methods
        $user = new class implements \Hypervel\Contracts\Auth\Authenticatable {
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
                // Return true only for 'foo', false for others
                return $ability === 'foo';
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

        $middleware = new CheckForAnyAbility($authFactory);

        $result = $middleware->handle($request, function ($req) use ($response) {
            return $response;
        }, 'foo', 'bar');

        $this->assertSame($response, $result);
    }

    public function testExceptionIsThrownIfTokenDoesntHaveAbility(): void
    {
        $this->expectException(\Hypervel\Sanctum\Exceptions\MissingAbilityException::class);

        $user = new class implements \Hypervel\Contracts\Auth\Authenticatable {
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

        $middleware = new CheckForAnyAbility($authFactory);

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

        $middleware = new CheckForAnyAbility($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }

    public function testExceptionIsThrownIfNoToken(): void
    {
        $this->expectException(\Hypervel\Auth\AuthenticationException::class);

        $user = new class implements \Hypervel\Contracts\Auth\Authenticatable {
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

        $middleware = new CheckForAnyAbility($authFactory);

        $middleware->handle($request, function ($req) {
            // Handler
        }, 'foo', 'bar');
    }
}
