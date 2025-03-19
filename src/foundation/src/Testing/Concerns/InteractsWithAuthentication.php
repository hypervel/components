<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Auth\Contracts\Authenticatable as UserContract;
use Hypervel\Auth\Contracts\FactoryContract as AuthManagerContract;

trait InteractsWithAuthentication
{
    /**
     * Set the currently logged in user for the application.
     */
    public function actingAs(UserContract $user, ?string $guard = null): static
    {
        return $this->be($user, $guard);
    }

    /**
     * Set the currently logged in user for the application.
     */
    public function be(UserContract $user, ?string $guard = null): static
    {
        if (isset($user->wasRecentlyCreated) && $user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        $this->app->get(AuthManagerContract::class)
            ->guard($guard)
            ->setUser($user);

        $this->app->get(AuthManagerContract::class)
            ->shouldUse($guard);

        return $this;
    }

    /**
     * Assert that the user is authenticated.
     */
    public function assertAuthenticated(?string $guard = null): static
    {
        $this->assertTrue($this->isAuthenticated($guard), 'The user is not authenticated');

        return $this;
    }

    /**
     * Assert that the user is not authenticated.
     */
    public function assertGuest(?string $guard = null): static
    {
        $this->assertFalse($this->isAuthenticated($guard), 'The user is authenticated');

        return $this;
    }

    /**
     * Return true if the user is authenticated, false otherwise.
     */
    protected function isAuthenticated(?string $guard = null): bool
    {
        return $this->app
            ->get(AuthManagerContract::class)
            ->guard($guard)
            ->check();
    }

    /**
     * Assert that the user is authenticated as the given user.
     */
    public function assertAuthenticatedAs(UserContract $user, ?string $guard = null): static
    {
        $expected = $this->app
            ->get(AuthManagerContract::class)
            ->guard($guard)
            ->user();

        $this->assertNotNull($expected, 'The current user is not authenticated.');

        $this->assertInstanceOf(
            get_class($expected),
            $user,
            'The currently authenticated user is not who was expected'
        );

        $this->assertSame(
            $expected->getAuthIdentifier(),
            $user->getAuthIdentifier(),
            'The currently authenticated user is not who was expected'
        );

        return $this;
    }

    /**
     * Assert that the given credentials are valid.
     */
    public function assertCredentials(array $credentials, ?string $guard = null): static
    {
        $this->assertTrue(
            $this->hasCredentials($credentials, $guard),
            'The given credentials are invalid.'
        );

        return $this;
    }

    /**
     * Assert that the given credentials are invalid.
     */
    public function assertInvalidCredentials(array $credentials, ?string $guard = null): static
    {
        $this->assertFalse(
            $this->hasCredentials($credentials, $guard),
            'The given credentials are valid.'
        );

        return $this;
    }

    /**
     * Return true if the credentials are valid, false otherwise.
     */
    protected function hasCredentials(array $credentials, ?string $guard = null): bool
    {
        $provider = $this->app
            ->get(AuthManagerContract::class)
            ->guard($guard)
            ->getProvider();

        $user = $provider->retrieveByCredentials($credentials);

        return $user && $provider->validateCredentials($user, $credentials);
    }
}
