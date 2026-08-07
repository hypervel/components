<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Queue\SerializesModels;

class RecoveryCodeReplaced
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Authenticatable $user,
        public string $code,
    ) {
    }
}
