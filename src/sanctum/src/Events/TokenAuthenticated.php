<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Events;

use Hypervel\Sanctum\PersonalAccessToken;

class TokenAuthenticated
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public PersonalAccessToken $token
    ) {
    }
}
