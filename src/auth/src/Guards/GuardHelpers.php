<?php

declare(strict_types=1);

namespace Hypervel\Auth\Guards;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\UserProvider;

/**
 * These methods are typically the same across all guards.
 */
trait GuardHelpers
{
    /**
     * Determine if the current user is authenticated. If not, throw an exception.
     *
     * @throws \Hypervel\Auth\AuthenticationException
     */
    public function authenticate(): Authenticatable
    {
        if (! is_null($user = $this->user())) {
            return $user;
        }

        throw new AuthenticationException();
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return ! is_null($this->user());
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): int|string|null
    {
        if ($this->user()) {
            return $this->user()->getAuthIdentifier();
        }

        return null;
    }

    /**
     * Get the user provider used by the guard.
     */
    public function getProvider(): UserProvider
    {
        return $this->provider;
    }

    /**
     * Set the user provider used by the guard.
     */
    public function setProvider(UserProvider $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return ! is_null($this->user());
    }

    /**
     * Log the given user ID into the application.
     */
    public function loginUsingId(mixed $id): Authenticatable|bool
    {
        if (! is_null($user = $this->provider->retrieveById($id))) {
            $this->login($user); /* @phpstan-ignore-line */

            return $user;
        }

        return false;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return (bool) $this->attempt($credentials, false);
    }

    /**
     * Determine if the user matches the credentials.
     */
    protected function hasValidCredentials(mixed $user, array $credentials): bool
    {
        if (! $user) {
            return false;
        }

        return $this->provider->validateCredentials($user, $credentials);
    }
}
