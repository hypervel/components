<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface Guard
{
    /**
     * Determine if the current user is authenticated.
     *
     * @phpstan-impure Authentication state may change mid-request through login(), logout(), and setUser().
     */
    public function check(): bool;

    /**
     * Determine if the current user is a guest.
     *
     * @phpstan-impure
     */
    public function guest(): bool;

    /**
     * Get the currently authenticated user.
     *
     * @phpstan-impure
     */
    public function user(): ?Authenticatable;

    /**
     * Get the ID for the currently authenticated user.
     *
     * @phpstan-impure
     */
    public function id(): int|string|null;

    /**
     * Validate a user's credentials.
     *
     * @phpstan-impure
     */
    public function validate(array $credentials = []): bool;

    /**
     * Determine if the guard has a user instance.
     *
     * @phpstan-impure
     */
    public function hasUser(): bool;

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static;
}
