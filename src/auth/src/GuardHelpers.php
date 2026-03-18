<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\UserProvider;

/**
 * These methods are typically the same across all guards.
 *
 * In Hypervel, guards are process-global singletons (cached in AuthManager::$guards),
 * so per-request user state cannot be stored as instance properties. Each guard must
 * implement its own coroutine-safe user(), setUser(), and hasUser() using Context.
 * This trait provides only the methods that are truly universal across all guards.
 */
trait GuardHelpers
{
    /**
     * The user provider implementation.
     *
     * Nullable because some guards (e.g. RequestGuard) may not require a provider.
     */
    protected ?UserProvider $provider = null;

    /**
     * Determine if the current user is authenticated. If not, throw an exception.
     *
     * @throws AuthenticationException
     */
    public function authenticate(): AuthenticatableContract
    {
        return $this->user() ?? throw new AuthenticationException();
    }

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
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
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Get the user provider used by the guard.
     */
    public function getProvider(): ?UserProvider
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
}
