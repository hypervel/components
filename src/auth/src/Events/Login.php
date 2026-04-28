<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Queue\SerializesModels;

class Login
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $guard the authentication guard name
     * @param Authenticatable $user the authenticated user
     * @param bool $remember indicates if the user should be "remembered"
     */
    public function __construct(
        public string $guard,
        public Authenticatable $user,
        public bool $remember,
    ) {
    }
}
