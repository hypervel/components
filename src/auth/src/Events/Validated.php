<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Queue\SerializesModels;

class Validated
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $guard the authentication guard name
     * @param Authenticatable $user the user retrieved and validated from the User Provider
     */
    public function __construct(
        public string $guard,
        public Authenticatable $user,
    ) {
    }
}
