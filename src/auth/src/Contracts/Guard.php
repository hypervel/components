<?php

declare(strict_types=1);

namespace Hypervel\Auth\Contracts;

interface Guard
{
    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool;

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool;

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable;

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): int|string|null;

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool;

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): void;
}
