<?php

declare(strict_types=1);

namespace Hypervel\Auth\Contracts;

interface StatefulGuard extends Guard
{
    /**
     * Attempt to authenticate a user using the given credentials.
     */
    public function attempt(array $credentials = []): bool;

    /**
     * Log a user into the application without sessions or cookies.
     */
    public function once(array $credentials = []): bool;

    /**
     * Log a user into the application.
     */
    public function login(Authenticatable $user): void;

    /**
     * Log the given user ID into the application.
     */
    public function loginUsingId(mixed $id): Authenticatable|bool;

    /**
     * Log the given user ID into the application without sessions or cookies.
     */
    public function onceUsingId(mixed $id): Authenticatable|bool;

    /**
     * Log the user out of the application.
     */
    public function logout(): void;
}
