<?php

declare(strict_types=1);

namespace Hypervel\Passport\Events;

class RefreshTokenCreated
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $refreshTokenId,
        public string $accessTokenId,
    ) {
    }
}
