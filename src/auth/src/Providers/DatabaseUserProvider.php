<?php

declare(strict_types=1);

namespace Hypervel\Auth\Providers;

use Closure;
use Hyperf\Contract\Arrayable;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Auth\Contracts\UserProvider;
use Hypervel\Auth\GenericUser;
use Hypervel\Hashing\Contracts\Hasher as HashContract;

class DatabaseUserProvider implements UserProvider
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected HashContract $hasher,
        protected string $table
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     *
     * @param mixed $identifier
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        $user = $this->connection->table($this->table)->find($identifier);

        return $this->getGenericUser($user);
    }

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $credentials = array_filter(
            $credentials,
            fn ($key) => ! str_contains((string) $key, 'password'),
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
     *
     * @param mixed $user
     */
    protected function getGenericUser($user): ?GenericUser
    {
        if (! is_null($user)) {
            return new GenericUser((array) $user);
        }

        return null;
    }

    /**
     * Validate a user against the given credentials.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return $this->hasher->check(
            $credentials['password'],
            $user->getAuthPassword()
        );
    }
}
