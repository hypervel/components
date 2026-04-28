<?php

declare(strict_types=1);

namespace Hypervel\Auth;

trait Authenticatable
{
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
     * Get the password for the user.
     */
    public function getAuthPassword(): string
    {
        /* @phpstan-ignore-next-line */
        return $this->password;
    }
}
