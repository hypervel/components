<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface Authenticatable
{
    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string;

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed;

    /**
     * Get the name of the password attribute for the user.
     */
    public function getAuthPasswordName(): string;

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): ?string;

    /**
     * Get the token value for the "remember me" session.
     */
    public function getRememberToken(): ?string;

    /**
     * Set the token value for the "remember me" session.
     */
    public function setRememberToken(string $value): void;

    /**
     * Get the column name for the "remember me" token.
     */
    public function getRememberTokenName(): string;
}
