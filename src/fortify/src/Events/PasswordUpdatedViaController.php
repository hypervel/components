<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Foundation\Events\Dispatchable;

class PasswordUpdatedViaController
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Authenticatable $user,
    ) {
    }
}
