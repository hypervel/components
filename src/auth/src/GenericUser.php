<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Contracts\Auth\Authenticatable as UserContract;

class GenericUser implements UserContract
{
    /**
     * Create a new generic User object.
     */
    public function __construct(
        protected array $attributes,
    ) {
    }

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->attributes[$this->getAuthIdentifierName()];
    }

    /**
     * Get the name of the password attribute for the user.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): ?string
    {
        return $this->attributes[$this->getAuthPasswordName()];
    }

    /**
     * Get the "remember me" token value.
     */
    public function getRememberToken(): ?string
    {
        return $this->attributes[$this->getRememberTokenName()];
    }

    /**
     * Set the "remember me" token value.
     */
    public function setRememberToken(string $value): void
    {
        $this->attributes[$this->getRememberTokenName()] = $value;
    }

    /**
     * Get the column name for the "remember me" token.
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    /**
     * Dynamically access the user's attributes.
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key];
    }

    /**
     * Dynamically set an attribute on the user.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Dynamically check if a value is set on the user.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Dynamically unset a value on the user.
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
}
