<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface Authenticatable
{
    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string;

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed;

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): string;
}
