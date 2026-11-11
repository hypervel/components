<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Queue\SerializesModels;

class Verified
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * Laravel documents a MustVerifyEmail user, but explicit verification also
     * dispatches this event for authenticated users that only use the trait.
     *
     * @param Authenticatable|MustVerifyEmail $user the verified user
     */
    public function __construct(
        public Authenticatable|MustVerifyEmail $user,
    ) {
    }
}
