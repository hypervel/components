<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Passkeys\Concerns\DispatchesEvents;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Events\PasskeyRegistered;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class StorePasskey
{
    use DispatchesEvents;

    /**
     * Validate and store a passkey for the user.
     *
     * @throws InvalidPasskeyException
     */
    public function __invoke(
        Authenticatable $user,
        string $name,
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options
    ): Passkey {
        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        $response = $this->getResponse($credential);

        $source = $this->validate($response, $options);

        $passkey = $this->createPasskey($user, $name, $source);

        $this->dispatchIfListening(
            PasskeyRegistered::class,
            static fn (): PasskeyRegistered => new PasskeyRegistered($user, $passkey),
        );

        return $passkey;
    }

    /**
     * Get the authenticator attestation response from the credential.
     *
     * @throws InvalidPasskeyException
     */
    protected function getResponse(PublicKeyCredential $credential): AuthenticatorAttestationResponse
    {
        if (! $credential->response instanceof AuthenticatorAttestationResponse) {
            throw InvalidPasskeyException::make('Unable to register passkey. Please try again.');
        }

        return $credential->response;
    }

    /**
     * Validate the credential and return the source.
     */
    protected function validate(
        AuthenticatorAttestationResponse $response,
        PublicKeyCredentialCreationOptions $options
    ): CredentialRecord {
        return WebAuthn::attestationValidator()->check(
            authenticatorAttestationResponse: $response,
            publicKeyCredentialCreationOptions: $options,
            host: Passkeys::relyingPartyId(),
        );
    }

    /**
     * Create the passkey record for the user.
     *
     * @throws InvalidPasskeyException
     */
    public function createPasskey(
        PasskeyUser $user,
        string $name,
        CredentialRecord $source
    ): Passkey {
        $credentialId = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);

        try {
            /** @var Passkey $passkey */
            $passkey = $user->passkeys()->create([
                'name' => $name,
                'credential_id' => $credentialId,
                'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw InvalidPasskeyException::make('Unable to register this passkey.');
        }

        return $passkey;
    }
}
