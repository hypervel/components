<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class FoundationAuthenticationTest extends TestCase
{
    use InteractsWithAuthentication;

    protected $app;

    protected array $credentials = [
        'email' => 'someone@hypervel.org',
        'password' => 'secret_password',
    ];

    protected function mockGuard(): Guard
    {
        $guard = m::mock(Guard::class);

        $auth = m::mock(AuthManager::class);
        $auth->shouldReceive('guard')
            ->once()
            ->andReturn($guard);

        $this->app = m::mock(Application::class);
        $this->app->shouldReceive('make')
            ->once()
            ->withArgs(['auth'])
            ->andReturn($auth);

        return $guard;
    }

    public function testAssertAuthenticated()
    {
        $this->mockGuard()
            ->shouldReceive('check')
            ->once()
            ->andReturn(true);

        $this->assertAuthenticated();
    }

    public function testAssertGuest()
    {
        $this->mockGuard()
            ->shouldReceive('check')
            ->once()
            ->andReturn(false);

        $this->assertGuest();
    }

    public function testAssertAuthenticatedAs()
    {
        $expected = m::mock(Authenticatable::class);
        $expected->shouldReceive('getAuthIdentifier')
            ->andReturn('1');

        $this->mockGuard()
            ->shouldReceive('user')
            ->once()
            ->andReturn($expected);

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')
            ->andReturn('1');

        $this->assertAuthenticatedAs($user);
    }

    protected function setupProvider(array $credentials): void
    {
        $user = m::mock(Authenticatable::class);

        $provider = m::mock(UserProvider::class);

        $provider->shouldReceive('retrieveByCredentials')
            ->with($credentials)
            ->andReturn($user);

        $provider->shouldReceive('validateCredentials')
            ->with($user, $credentials)
            ->andReturn($this->credentials === $credentials);

        $this->mockGuard()
            ->shouldReceive('getProvider')
            ->once()
            ->andReturn($provider);
    }

    public function testAssertCredentials()
    {
        $this->setupProvider($this->credentials);

        $this->assertCredentials($this->credentials);
    }

    public function testAssertCredentialsMissing()
    {
        $credentials = [
            'email' => 'invalid',
            'password' => 'credentials',
        ];

        $this->setupProvider($credentials);

        $this->assertInvalidCredentials($credentials);
    }
}
