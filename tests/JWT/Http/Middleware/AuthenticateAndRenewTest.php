<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Http\Middleware;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Http\Request;
use Hypervel\JWT\Exceptions\SecretMissingException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\Http\Middleware\AuthenticateAndRenew;
use Hypervel\JWT\JwtGuard;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAndRenewTest extends TestCase
{
    public function testAuthenticatesRefreshesTokenAndSetsAuthorizationHeader(): void
    {
        $guard = m::mock(JwtGuard::class);
        $guard->shouldReceive('userOrFail')->once()->andReturn(m::mock(Authenticatable::class));
        $guard->shouldReceive('refresh')->once()->andReturn('new-token');

        $middleware = new AuthenticateAndRenew($this->authFactory($guard));

        $response = $middleware->handle(new Request, fn () => new Response('OK'), 'jwt');

        $this->assertSame('Bearer new-token', $response->headers->get('Authorization'));
    }

    public function testMissingTokenThrowsAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);

        $guard = m::mock(JwtGuard::class);
        $guard->shouldReceive('userOrFail')->once()->andReturn(m::mock(Authenticatable::class));
        $guard->shouldReceive('refresh')->once()->andReturn(null);

        (new AuthenticateAndRenew($this->authFactory($guard)))->handle(new Request, fn () => new Response('OK'), 'jwt');
    }

    public function testInvalidTokenThrowsAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);

        $guard = m::mock(JwtGuard::class);
        $guard->shouldReceive('userOrFail')->once()->andThrow(new TokenInvalidException('Invalid token.'));
        $guard->shouldNotReceive('refresh');

        (new AuthenticateAndRenew($this->authFactory($guard)))->handle(new Request, fn () => new Response('OK'), 'jwt');
    }

    public function testSecretMisconfigurationIsNotConvertedToAuthenticationException(): void
    {
        $this->expectException(SecretMissingException::class);

        $guard = m::mock(JwtGuard::class);
        $guard->shouldReceive('userOrFail')->once()->andThrow(new SecretMissingException('Secret is not set.'));
        $guard->shouldNotReceive('refresh');

        (new AuthenticateAndRenew($this->authFactory($guard)))->handle(new Request, fn () => new Response('OK'), 'jwt');
    }

    public function testRequiresJwtGuard(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT middleware requires a JWT guard.');

        $guard = m::mock(Guard::class);

        (new AuthenticateAndRenew($this->authFactory($guard)))->handle(new Request, fn () => new Response('OK'), 'web');
    }

    /**
     * Create an auth factory returning the given guard.
     */
    private function authFactory(Guard|JwtGuard $guard): Factory
    {
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('guard')->andReturn($guard);

        return $factory;
    }
}
