<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\GenericUser;
use Hypervel\Config\Repository as ConfigRepository;
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

    /**
     * Register a real guard for the given user as the application's default guard.
     */
    protected function getRealGuard(?Authenticatable $user = null): Guard
    {
        $guard = new class($user) implements Guard {
            /**
             * Create a new guard instance.
             */
            public function __construct(protected ?Authenticatable $user)
            {
            }

            /**
             * Determine if the current user is authenticated.
             */
            public function check(): bool
            {
                return ! is_null($this->user);
            }

            /**
             * Determine if the current user is a guest.
             */
            public function guest(): bool
            {
                return is_null($this->user);
            }

            /**
             * Get the currently authenticated user.
             */
            public function user(): ?Authenticatable
            {
                return $this->user;
            }

            /**
             * Get the ID for the currently authenticated user.
             */
            public function id(): int|string|null
            {
                return $this->user?->getAuthIdentifier();
            }

            /**
             * Validate a user's credentials.
             */
            public function validate(array $credentials = []): bool
            {
                return false;
            }

            /**
             * Determine if the guard has a user instance.
             */
            public function hasUser(): bool
            {
                return ! is_null($this->user);
            }

            /**
             * Set the current user.
             */
            public function setUser(Authenticatable $user): static
            {
                $this->user = $user;

                return $this;
            }
        };

        $this->app = new Application;
        $this->app->instance('config', new ConfigRepository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => ['web' => ['driver' => 'test']],
            ],
        ]));

        $auth = new AuthManager($this->app);
        $auth->extend('test', fn (): Guard => $guard);
        $this->app->instance('auth', $auth);

        return $guard;
    }

    public function testAssertAuthenticated(): void
    {
        $this->getRealGuard(new GenericUser(['id' => 1]));

        $this->assertAuthenticated();
    }

    public function testAssertGuest(): void
    {
        $this->getRealGuard();

        $this->assertGuest();
    }

    public function testAssertAuthenticatedAs(): void
    {
        $this->getRealGuard(new GenericUser(['id' => 1]));

        $user = new GenericUser(['id' => 1]);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Set up the user provider for the given credentials.
     */
    protected function setupProvider(array $credentials): void
    {
        $user = new GenericUser([]);

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
