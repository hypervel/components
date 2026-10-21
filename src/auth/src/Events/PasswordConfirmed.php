<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

class PasswordConfirmed
{
    /**
     * Create a new event instance.
     *
     * @param string $guard the authentication guard name
     * @param int $confirmedAt the Unix timestamp of the confirmation
     */
    public function __construct(
        public string $guard,
        public int $confirmedAt,
    ) {
    }
}
