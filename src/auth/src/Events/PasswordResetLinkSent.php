<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Queue\SerializesModels;

class PasswordResetLinkSent
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param CanResetPassword $user the user instance
     */
    public function __construct(
        public CanResetPassword $user,
    ) {
    }
}
