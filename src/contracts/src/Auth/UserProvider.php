<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

use SensitiveParameter;

interface UserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById(mixed $identifier): ?Authenticatable;

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     */
    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?Authenticatable;

    /**
     * Update the "remember me" token for the given user in storage.
     */
    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] string $token): void;

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable;

    /**
     * Validate a user against the given credentials.
     */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool;

    /**
     * Rehash the user's password if required and supported.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void;
}
