<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Contracts\Auth\Authenticatable as UserContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use SensitiveParameter;

class EloquentUserProvider implements UserProvider
{
    /**
     * The callback that may modify the user retrieval queries.
     *
     * @var null|(Closure(Builder):mixed)
     */
    protected ?Closure $queryCallback = null;

    /**
     * Create a new database user provider.
     *
     * @param class-string<Model&UserContract> $model
     */
    public function __construct(
        protected HasherContract $hasher,
        protected string $model,
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById(mixed $identifier): ?UserContract
    {
        $model = $this->createModel();

        return $this->newModelQuery($model) /* @phpstan-ignore return.type */
            ->where($model->getAuthIdentifierName(), $identifier)
            ->first();
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     */
    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?UserContract
    {
        $model = $this->createModel();

        /** @var null|(Model&UserContract) $retrievedModel */
        $retrievedModel = $this->newModelQuery($model)->where(
            $model->getAuthIdentifierName(),
            $identifier
        )->first();

        if (! $retrievedModel) {
            return null;
        }

        $rememberToken = $retrievedModel->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $retrievedModel : null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param Model&UserContract $user
     */
    public function updateRememberToken(UserContract $user, #[SensitiveParameter] string $token): void
    {
        $user->setRememberToken($token);

        $timestamps = $user->timestamps;

        $user->timestamps = false;

        $user->save();

        $user->timestamps = $timestamps;
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

        return $query->first(); /* @phpstan-ignore return.type */
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
     *
     * @param Model&UserContract $user
     */
    public function rehashPasswordIfRequired(UserContract $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        if (! $this->hasher->needsRehash($user->getAuthPassword()) && ! $force) {
            return;
        }

        $user->forceFill([
            $user->getAuthPasswordName() => $this->hasher->make($credentials['password']),
        ])->save();
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
     *
     * @return Model&UserContract
     */
    public function createModel(): Model
    {
        $class = '\\' . ltrim($this->model, '\\');

        return new $class;
    }

    /**
     * Get the hasher implementation.
     */
    public function getHasher(): HasherContract
    {
        return $this->hasher;
    }

    /**
     * Set the hasher implementation.
     */
    public function setHasher(HasherContract $hasher): static
    {
        $this->hasher = $hasher;

        return $this;
    }

    /**
     * Get the name of the Eloquent user model.
     *
     * @return class-string<Model&UserContract>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set the name of the Eloquent user model.
     *
     * @param class-string<Model&UserContract> $model
     */
    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the callback that modifies the query before retrieving users.
     *
     * @return null|(Closure(Builder):mixed)
     */
    public function getQueryCallback(): ?Closure
    {
        return $this->queryCallback;
    }

    /**
     * Set the callback to modify the query before retrieving users.
     *
     * @param null|(Closure(Builder):mixed) $queryCallback
     */
    public function withQuery(?Closure $queryCallback = null): static
    {
        $this->queryCallback = $queryCallback;

        return $this;
    }
}
