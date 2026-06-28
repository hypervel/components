<?php

declare(strict_types=1);

namespace Hypervel\JWT\Contracts;

interface JWTSubject
{
    /**
     * Get the identifier that will be stored in the subject claim.
     */
    public function getJWTIdentifier(): mixed;

    /**
     * Return custom claims to add to the token.
     */
    public function getJWTCustomClaims(): array;
}
