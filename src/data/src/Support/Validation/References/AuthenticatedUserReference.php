<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\References;

use Hypervel\Support\Facades\Auth;

class AuthenticatedUserReference implements ExternalReference
{
    /**
     * Create an authenticated user reference.
     */
    public function __construct(
        public readonly ?string $property = null,
        public readonly ?string $guard = null,
    ) {
    }

    /**
     * Resolve the authenticated user, or the given property of it, from the guard.
     */
    public function getValue(): mixed
    {
        $user = Auth::guard($this->guard)->user();

        if ($user === null) {
            return null;
        }

        if ($this->property === null) {
            return $user;
        }

        return data_get($user, $this->property);
    }
}
