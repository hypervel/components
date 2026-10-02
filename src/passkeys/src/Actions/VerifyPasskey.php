<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Actions;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Passkeys\Concerns\DispatchesEvents;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Events\PasskeyVerified;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class VerifyPasskey
{
    use DispatchesEvents;

    /**
     * Validate the passkey credential and return the passkey.
     *
     * @throws InvalidPasskeyException
     */
    public function __invoke(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
        ?PasskeyUser $user = null
    ): Passkey {
        $response = $this->getResponse($credential);

        $ownerType = $user instanceof PasskeyUser
            ? null
            : $this->ownerMorphClassForGuard(Passkeys::guard());
        $passkeyModel = Passkeys::passkeyModel();

        // The row lock only holds inside a transaction on the passkey model's own connection.
        return DB::connection((new $passkeyModel)->getConnectionName())->transaction(function () use ($credential, $options, $user, $response, $ownerType): Passkey {
            $passkey = $this->getPasskey($credential, lock: true);

            $this->ensurePasskeyBelongsToUser($passkey, $user);

            $verifiedUser = $user;

            if (! $verifiedUser instanceof PasskeyUser) {
                /** @var string $ownerType */
                $verifiedUser = $this->resolvePasskeyOwner($passkey, $ownerType);
            }

            $source = $this->validate($response, $passkey, $options);

            $this->updatePasskey($passkey, $source);

            $this->dispatchIfListening(
                PasskeyVerified::class,
                static fn (): PasskeyVerified => new PasskeyVerified($verifiedUser, $passkey),
            );

            return $passkey;
        });
    }

    /**
     * Get the authenticator assertion response from the credential.
     *
     * @throws InvalidPasskeyException
     */
    protected function getResponse(PublicKeyCredential $credential): AuthenticatorAssertionResponse
    {
        if (! $credential->response instanceof AuthenticatorAssertionResponse) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }

        return $credential->response;
    }

    /**
     * Get the passkey by credential ID.
     *
     * @throws InvalidPasskeyException
     */
    public function getPasskey(PublicKeyCredential $credential, bool $lock = false): Passkey
    {
        // Assertion ceremonies do not run CheckCredentialId, so enforce its CTAP2 limit before lookup.
        if (strlen($credential->rawId) > 1023) {
            throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);
        $passkeyModel = Passkeys::passkeyModel();

        $query = $passkeyModel::query()->where('credential_id', $credentialId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $passkey = $query->first();

        if (! $passkey instanceof Passkey) {
            throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
        }

        return $passkey;
    }

    /**
     * Ensure the passkey belongs to the expected user.
     *
     * @throws InvalidPasskeyException
     */
    public function ensurePasskeyBelongsToUser(Passkey $passkey, ?PasskeyUser $user): void
    {
        if (! $user instanceof PasskeyUser) {
            return;
        }

        $identifier = $user->getKey();

        if (! is_scalar($identifier)
            || $passkey->user_type !== $user->getMorphClass()
            || (string) $passkey->user_id !== (string) $identifier) {
            throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
        }
    }

    /**
     * Resolve the passkey owner for passwordless login.
     *
     * @throws InvalidPasskeyException
     */
    protected function resolvePasskeyOwner(Passkey $passkey, string $ownerType): PasskeyUser
    {
        // Compare the stored type first so another provider's owner type is never resolved.
        if ($passkey->user_type !== $ownerType) {
            throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
        }

        $user = $passkey->user;

        if (! $user instanceof PasskeyUser) {
            throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
        }

        return $user;
    }

    /**
     * Get the owner morph class supported by the selected guard.
     */
    protected function ownerMorphClassForGuard(StatefulGuard $guard): string
    {
        if (! method_exists($guard, 'getProvider')) {
            throw new RuntimeException('Passkey passwordless login requires an Eloquent authentication guard provider.');
        }

        $provider = $guard->getProvider();

        if (! $provider instanceof EloquentUserProvider) {
            throw new RuntimeException('Passkey passwordless login requires an Eloquent authentication guard provider.');
        }

        $model = $provider->getModel();
        $owner = new $model;

        return $owner->getMorphClass();
    }

    /**
     * Validate the credential against the stored passkey.
     */
    protected function validate(
        AuthenticatorAssertionResponse $response,
        Passkey $passkey,
        PublicKeyCredentialRequestOptions $options
    ): CredentialRecord {
        /** @var CredentialRecord $source */
        $source = WebAuthn::fromJson(
            json_encode($passkey->credential, JSON_THROW_ON_ERROR),
            CredentialRecord::class
        );
        $validator = WebAuthn::assertionValidator();

        try {
            return $validator->check(
                credentialRecord: $source,
                authenticatorAssertionResponse: $response,
                publicKeyCredentialRequestOptions: $options,
                host: $this->hostFromOptions($options),
                userHandle: $source->userHandle,
            );
        } catch (WebauthnException) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }
    }

    /**
     * Get the relying party ID stored in the verification options.
     */
    protected function hostFromOptions(PublicKeyCredentialRequestOptions $options): string
    {
        if (! is_string($options->rpId) || $options->rpId === '') {
            throw new RuntimeException('Passkey verification options must contain a relying party ID.');
        }

        return $options->rpId;
    }

    /**
     * Update the passkey with the latest credential data.
     *
     * The credential must be persisted after each use to store the updated
     * signature counter, which is used to detect cloned authenticators.
     */
    public function updatePasskey(Passkey $passkey, CredentialRecord $source): void
    {
        $passkey->forceFill([
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
            'last_used_at' => Date::now(),
        ])->save();
    }
}
