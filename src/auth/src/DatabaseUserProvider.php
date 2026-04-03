<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Contracts\Auth\Authenticatable as UserContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\ConnectionInterface;
use SensitiveParameter;

class DatabaseUserProvider implements UserProvider
{
    /**
     * Create a new database user provider.
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected HasherContract $hasher,
        protected string $table,
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById(mixed $identifier): ?UserContract
    {
        $user = $this->connection->table($this->table)->find($identifier);

        return $this->getGenericUser($user);
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     */
    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?UserContract
    {
        $user = $this->getGenericUser(
            $this->connection->table($this->table)->find($identifier)
        );

        return $user && $user->getRememberToken() && hash_equals($user->getRememberToken(), $token)
            ? $user
            : null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     */
    public function updateRememberToken(UserContract $user, #[SensitiveParameter] string $token): void
    {
        $this->connection->table($this->table)
            ->where($user->getAuthIdentifierName(), $user->getAuthIdentifier())
            ->update([$user->getRememberTokenName() => $token]);
    }

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?UserContract
    {
        $credentials = array_filter(
            $credentials,
            fn ($key) => ! is_string($key) || ! str_contains($key, 'password'),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($credentials)) {
            return null;
        }

        // First we will add each credential element to the query as a where clause.
        // Then we can execute the query and, if we found a user, return it in a
        // generic "user" object that will be utilized by the Guard instances.
        $query = $this->connection->table($this->table);

        foreach ($credentials as $key => $value) {
            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        // Now we are ready to execute the query to see if we have a user matching
        // the given credentials. If not, we will just return null and indicate
        // that there are no matching users from the given credential arrays.
        $user = $query->first();

        return $this->getGenericUser($user);
    }

    /**
     * Get the generic user.
     */
    protected function getGenericUser(mixed $user): ?GenericUser
    {
        if (! is_null($user)) {
            return new GenericUser((array) $user);
        }

        return null;
    }

    /**
     * Validate a user against the given credentials.
     */
    public function validateCredentials(UserContract $user, #[SensitiveParameter] array $credentials): bool
    {
        if (is_null($plain = $credentials['password'])) {
            return false;
        }

        if (is_null($hashed = $user->getAuthPassword())) {
            return false;
        }

        return $this->hasher->check($plain, $hashed);
    }

    /**
     * Rehash the user's password if required and supported.
     */
    public function rehashPasswordIfRequired(UserContract $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        if (! $this->hasher->needsRehash($user->getAuthPassword()) && ! $force) {
            return;
        }

        $this->connection->table($this->table)
            ->where($user->getAuthIdentifierName(), $user->getAuthIdentifier())
            ->update([$user->getAuthPasswordName() => $this->hasher->make($credentials['password'])]);
    }
}
