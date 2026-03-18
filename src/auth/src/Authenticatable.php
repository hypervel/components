<?php

declare(strict_types=1);

namespace Hypervel\Auth;

trait Authenticatable
{
    /**
     * The column name of the password field used during authentication.
     */
    protected string $authPasswordName = 'password';

    /**
     * The column name of the "remember me" token.
     */
    protected string $rememberTokenName = 'remember_token';

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->{$this->getAuthIdentifierName()};
    }

    /**
     * Get the unique broadcast identifier for the user.
     */
    public function getAuthIdentifierForBroadcasting(): mixed
    {
        return $this->getAuthIdentifier();
    }

    /**
     * Get the name of the password attribute for the user.
     */
    public function getAuthPasswordName(): string
    {
        return $this->authPasswordName;
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): ?string
    {
        return $this->{$this->getAuthPasswordName()};
    }

    /**
     * Get the token value for the "remember me" session.
     */
    public function getRememberToken(): ?string
    {
        if (! empty($this->getRememberTokenName())) {
            return (string) $this->{$this->getRememberTokenName()};
        }

        return null;
    }

    /**
     * Set the token value for the "remember me" session.
     */
    public function setRememberToken(string $value): void
    {
        if (! empty($this->getRememberTokenName())) {
            $this->{$this->getRememberTokenName()} = $value;
        }
    }

    /**
     * Get the column name for the "remember me" token.
     */
    public function getRememberTokenName(): string
    {
        return $this->rememberTokenName;
    }
}
