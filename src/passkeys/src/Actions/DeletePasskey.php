<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Passkeys\Concerns\DispatchesEvents;
use Hypervel\Passkeys\Events\PasskeyDeleted;
use Hypervel\Passkeys\Passkey;

class DeletePasskey
{
    use DispatchesEvents;

    /**
     * Delete the given passkey.
     */
    public function __invoke(Authenticatable $user, Passkey $passkey): void
    {
        $passkey->delete();

        $this->dispatchIfListening(
            PasskeyDeleted::class,
            static fn (): PasskeyDeleted => new PasskeyDeleted($user, $passkey),
        );
    }
}
