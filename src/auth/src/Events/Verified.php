<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Queue\SerializesModels;

class Verified
{
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param MustVerifyEmail $user the verified user
     */
    public function __construct(
        public MustVerifyEmail $user,
    ) {
    }
}
