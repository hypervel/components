<?php

declare(strict_types=1);

namespace Hypervel\Auth\Providers;

use Closure;
use Hyperf\Contract\Arrayable;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Model;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\UserProvider;
use Hypervel\Hashing\Contracts\Hasher as HashContract;

use function Hyperf\Support\with;

class EloquentUserProvider implements UserProvider
{
    /**
     * The callback that may modify the user retrieval queries.
     *
     * @var null|(Closure(\Hyperf\Database\Model\Builder):mixed)
     */
    protected $queryCallback;

    public function __construct(
        protected HashContract $hasher,
        protected string $model
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     *
     * @param mixed $identifier
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        $model = $this->createModel();

        /* @phpstan-ignore-next-line */
        return $this->newModelQuery($model)
            /* @phpstan-ignore-next-line */
            ->where($model->getAuthIdentifierName(), $identifier)
            ->first();
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
        // Eloquent User "model" that will be utilized by the Guard instances.
        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        /* @phpstan-ignore-next-line */
        return $query->first();
    }

    /**
     * Get the first key from the credential array.
     */
    protected function firstCredentialKey(array $credentials): ?string
    {
        foreach ($credentials as $key => $value) {
            return $key;
        }

        return null;
    }

    /**
     * Validate a user against the given credentials.
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = $credentials['password'];

        return $this->hasher->check($plain, $user->getAuthPassword());
    }

    /**
     * Get a new query builder for the model instance.
     */
    protected function newModelQuery(?Model $model = null): Builder
    {
        $query = is_null($model)
            ? $this->createModel()->newQuery()
            : $model->newQuery();

        with($query, $this->queryCallback);

        return $query;
    }

    /**
     * Create a new instance of the model.
     */
    public function createModel(): Model
    {
        $class = '\\' . ltrim($this->model, '\\');

        return new $class();
    }

    /**
     * Gets the hasher implementation.
     */
    public function getHasher(): HashContract
    {
        return $this->hasher;
    }

    /**
     * Sets the hasher implementation.
     *
     * @return $this
     */
    public function setHasher(HashContract $hasher): static
    {
        $this->hasher = $hasher;

        return $this;
    }

    /**
     * Gets the name of the Eloquent user model.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Sets the name of the Eloquent user model.
     *
     * @return $this
     */
    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the callback that modifies the query before retrieving users.
     */
    public function getQueryCallback(): ?Closure
    {
        return $this->queryCallback;
    }

    /**
     * Sets the callback to modify the query before retrieving users.
     *
     * @param null|(Closure(\Hyperf\Database\Model\Builder):mixed) $queryCallback
     *
     * @return $this
     */
    public function withQuery($queryCallback = null): static
    {
        $this->queryCallback = $queryCallback;

        return $this;
    }
}
