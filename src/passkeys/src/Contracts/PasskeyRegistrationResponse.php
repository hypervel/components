<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Contracts;

use Hypervel\Contracts\Support\Responsable;
use Hypervel\Passkeys\Passkey;

interface PasskeyRegistrationResponse extends Responsable
{
    /**
     * Set the passkey that was registered.
     */
    public function withPasskey(Passkey $passkey): static;
}
