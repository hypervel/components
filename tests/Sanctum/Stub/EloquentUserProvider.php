<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Stub;

use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\UserProvider;

/**
 * Simple user provider for testing
 */
class EloquentUserProvider implements UserProvider
{
    public function __construct(
        protected $hasher,
        protected string $model
    ) {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->model::find($identifier);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }
    
    /**
     * Get the model class name
     */
    public function getModel(): string
    {
        return $this->model;
    }
}