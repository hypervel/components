<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

interface JwtSubject
{
    /**
     * Get the identifier that will be stored in the subject claim.
     */
    public function getJwtIdentifier(): mixed;

    /**
     * Return custom claims to add to the token.
     */
    public function getJwtCustomClaims(): array;
}
