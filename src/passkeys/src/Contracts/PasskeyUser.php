<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Contracts;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Passkeys\Passkey;

interface PasskeyUser extends Authenticatable
{
    /**
     * Get the passkeys associated with the user.
     *
     * @return MorphMany<Passkey, Model>
     */
    public function passkeys(): MorphMany;

    /**
     * Determine if the user has any passkeys enabled.
     */
    public function hasPasskeysEnabled(): bool;

    /**
     * Get the value of the model's primary key.
     */
    public function getKey(): mixed;

    /**
     * Get the model's morph class for polymorphic ownership.
     */
    public function getMorphClass(): string;

    /**
     * Get the unique user handle for WebAuthn.
     */
    public function getPasskeyUserHandle(): string;

    /**
     * Get the display name for WebAuthn registration.
     */
    public function getPasskeyDisplayName(): string;

    /**
     * Get the username for WebAuthn registration.
     */
    public function getPasskeyUsername(): string;
}
