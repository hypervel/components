<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Passkeys\Passkey;
use Hypervel\Queue\SerializesModels;

class PasskeyVerified
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Passkey $passkey
    ) {
    }
}
