<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Support\Attributes\BootOrTestsOnly;

/**
 * These methods are typically the same across all guards.
 *
 * In Hypervel, guards are process-global singletons (cached in AuthManager::$guards),
 * so per-request user state cannot be stored as instance properties. Each guard must
 * implement its own coroutine-safe user(), setUser(), and hasUser() using Context.
 * This trait provides only the methods that are truly universal across all guards.
 *
 * @template TProvider of UserProvider = UserProvider
 */
trait GuardHelpers
{
    /**
     * The user provider implementation.
     *
     * Nullable because some guards (e.g. RequestGuard) may not require a provider.
     *
     * @var null|TProvider
     */
    protected ?UserProvider $provider = null;

    /**
     * The sequence used to build unique per-guard context key prefixes.
     *
     * Each class using this trait has its own sequence, so the prefix also
     * includes that class.
     */
    // Do not reset this; live context entries may still use earlier prefixes.
    private static int $contextKeySequence = 0;

    /**
     * The prefix of this guard's coroutine context keys.
     *
     * Users and credentials cached in context belong to the guard that
     * resolved them, so a guard created later with the same name, such as
     * after AuthManager::forgetGuards(), starts without them.
     */
    private ?string $contextKeyPrefix = null;

    /**
     * Determine if the current user is authenticated. If not, throw an exception.
     *
     * @throws AuthenticationException
     */
    public function authenticate(): AuthenticatableContract
    {
        return $this->user() ?? throw new AuthenticationException;
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
     *
     * @return null|TProvider
     */
    public function getProvider(): ?UserProvider
    {
        return $this->provider;
    }

    /**
     * Set the user provider used by the guard.
     *
     * The provider is stored on the worker-lifetime guard and affects every
     * subsequent request.
     *
     * @param TProvider $provider
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function setProvider(UserProvider $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Get this guard's coroutine context key for the given entry.
     */
    protected function contextKey(string $key): string
    {
        return ($this->contextKeyPrefix ??= '__auth.guards.' . self::class . '.' . ++self::$contextKeySequence . '.') . $key;
    }
}
