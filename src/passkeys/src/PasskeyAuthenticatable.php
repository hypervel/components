<?php

declare(strict_types=1);

namespace Hypervel\Passkeys;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Passkeys\Contracts\PasskeyUser;

/**
 * @phpstan-require-implements PasskeyUser
 */
trait PasskeyAuthenticatable
{
    /**
     * Boot the passkey authenticatable trait for a model.
     */
    public static function bootPasskeyAuthenticatable(): void
    {
        static::deleting(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            /** @var Model&PasskeyUser $model */
            $model->passkeys()->delete();
        });
    }

    /**
     * Get the passkeys associated with the user.
     *
     * @return MorphMany<Passkey, Model>
     */
    public function passkeys(): MorphMany
    {
        return $this->morphMany(Passkeys::passkeyModel(), 'user');
    }

    /**
     * Determine if the user has any passkeys enabled.
     */
    public function hasPasskeysEnabled(): bool
    {
        return $this->passkeys()->exists();
    }

    /**
     * Get the unique user handle for WebAuthn.
     *
     * This should be a stable identifier that does not reveal PII.
     */
    public function getPasskeyUserHandle(): string
    {
        return hash_hmac(
            'sha256',
            $this->getTable() . '|' . $this->getKey(),
            Passkeys::userHandleSecret(),
            binary: true,
        );
    }

    /**
     * Get the display name for WebAuthn registration.
     *
     * Shown prominently in authenticator UIs (registration prompts,
     * account pickers, password manager entries). Falls back from
     * `name` to `email` to the auth identifier when columns are absent.
     */
    public function getPasskeyDisplayName(): string
    {
        return $this->getAttribute('name')
            ?? $this->getAttribute('email')
            ?? (string) $this->getAuthIdentifier();
    }

    /**
     * Get the username for WebAuthn registration.
     *
     * Used as the account identifier in authenticator UIs, typically
     * rendered as the subtitle beneath the display name. Falls back
     * from `email` to the auth identifier when the column is absent.
     */
    public function getPasskeyUsername(): string
    {
        return $this->getAttribute('email')
            ?? (string) $this->getAuthIdentifier();
    }
}
