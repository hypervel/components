<?php

declare(strict_types=1);

namespace Hypervel\Passport\Events;

class AccessTokenRevoked
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $tokenId,
    ) {
    }
}
