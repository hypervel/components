<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Passport\Contracts\OAuthenticatable;
use SensitiveParameter;

class PassportUserProvider implements UserProvider
{
    /**
     * Create a new passport user provider.
     */
    public function __construct(
        protected UserProvider $provider,
        protected string $providerName,
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     *
     * @param int|string $identifier
     * @return null|OAuthenticatable
     */
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        /** @var null|OAuthenticatable $user */
        $user = $this->provider->retrieveById($identifier);

        return $user;
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param int|string $identifier
     * @return null|OAuthenticatable
     */
    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?Authenticatable
    {
        /** @var null|OAuthenticatable $user */
        $user = $this->provider->retrieveByToken($identifier, $token);

        return $user;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param OAuthenticatable $user
     */
    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] string $token): void
    {
        $this->provider->updateRememberToken($user, $token);
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @return null|OAuthenticatable
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        /** @var null|OAuthenticatable $user */
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user;
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param OAuthenticatable $user
     */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        return $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * Rehash the user's password if required and supported.
     *
     * @param OAuthenticatable $user
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        $this->provider->rehashPasswordIfRequired($user, $credentials, $force);
    }

    /**
     * Get the name of the user provider.
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }
}
