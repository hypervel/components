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

class FoundationAuthenticationTest extends TestCase
{
    use InteractsWithAuthentication;

    protected Application $app;

    protected array $credentials = [
        'email' => 'someone@hypervel.org',
        'password' => 'secret_password',
    ];

    /**
     * Mock the authentication guard resolved by the application.
     */
    protected function mockGuard(): Guard
    {
        $guard = m::mock(Guard::class);

        $auth = m::mock(AuthManager::class);
        $auth->expects('guard')
            ->andReturn($guard);

        $this->app = m::mock(Application::class);
        $this->app->expects('make')
            ->withArgs(['auth'])
            ->andReturn($auth);

        return $guard;
    }

    public function testAssertAuthenticated(): void
    {
        $this->mockGuard()
            ->expects('check')
            ->andReturn(true);

        $this->assertAuthenticated();
    }

    public function testAssertGuest(): void
    {
        $this->mockGuard()
            ->expects('check')
            ->andReturn(false);

        $this->assertGuest();
    }

    public function testAssertAuthenticatedAs(): void
    {
        $expected = m::mock(Authenticatable::class);
        $expected->expects('getAuthIdentifier')
            ->andReturn('1');

        $this->mockGuard()
            ->expects('user')
            ->andReturn($expected);

        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')
            ->andReturn('1');

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Set up the user provider for the given credentials.
     */
    protected function setupProvider(array $credentials): void
    {
        $user = m::mock(Authenticatable::class);

        $provider = m::mock(UserProvider::class);

        $provider->expects('retrieveByCredentials')
            ->with($credentials)
            ->andReturn($user);

        $provider->expects('validateCredentials')
            ->with($user, $credentials)
            ->andReturn($this->credentials === $credentials);

        $this->mockGuard()
            ->expects('getProvider')
            ->andReturn($provider);
    }

    public function testAssertCredentials(): void
    {
        $this->setupProvider($this->credentials);

        $this->assertCredentials($this->credentials);
    }

    public function testAssertCredentialsMissing(): void
    {
        $credentials = [
            'email' => 'invalid',
            'password' => 'credentials',
        ];

        $this->setupProvider($credentials);

        $this->assertInvalidCredentials($credentials);
    }
}
