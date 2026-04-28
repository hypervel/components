<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Queue\SerializesModels;

class PasswordReset
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Authenticatable $user the user
     */
    public function __construct(
        public Authenticatable $user,
    ) {
    }
}
