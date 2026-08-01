<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface StatefulGuard extends Guard
{
    /**
     * Attempt to authenticate a user using the given credentials.
     *
     * @phpstan-impure
     */
    public function attempt(array $credentials = [], bool $remember = false): bool;

    /**
     * Log a user into the application without sessions or cookies.
     *
     * @phpstan-impure
     */
    public function once(array $credentials = []): bool;

    /**
     * Log a user into the application.
     */
    public function login(Authenticatable $user, bool $remember = false): void;

    /**
     * Log the given user ID into the application.
     *
     * @phpstan-impure
     */
    public function loginUsingId(mixed $id, bool $remember = false): Authenticatable|false;

    /**
     * Log the given user ID into the application without sessions or cookies.
     *
     * @phpstan-impure
     */
    public function onceUsingId(mixed $id): Authenticatable|false;

    /**
     * Determine if the user was authenticated via "remember me" cookie.
     *
     * @phpstan-impure
     */
    public function viaRemember(): bool;

    /**
     * Log the user out of the application.
     */
    public function logout(): void;
}
