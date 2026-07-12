<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Fixtures;

use InvalidArgumentException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorData;
use Webauthn\CollectedClientData;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\U2FPublicKey;

trait WebAuthnFixtures
{
    /**
     * Create a fixture EC keypair.
     *
     * @return array{private_key_pem: string, cose_public_key: string}
     */
    protected function fixtureEcKeypair(): array
    {
        static $keypair = null;

        if (is_array($keypair)) {
            return $keypair;
        }

        $privateKeyPem = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIExwigMuj7pGzk+XVIKVp72gYQc6AU//5HlUHb3X5HGRoAoGCCqGSM49
AwEHoUQDQgAE53mVK/HTD1mPae7VZ8uzETJC7ZLmAyWKa75YyZ9lNbHFl8oSKWJe
RYf/wGQSBmBTBSaU+rRuRbuVAx2zexNCzQ==
-----END EC PRIVATE KEY-----
PEM;

        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new InvalidArgumentException('Unable to load fixture EC private key.');
        }

        $details = openssl_pkey_get_details($privateKey);

        if (! is_array($details) || ! isset($details['ec']) || ! is_array($details['ec'])) {
            throw new InvalidArgumentException('Unable to read fixture EC key details.');
        }

        $ec = $details['ec'];

        if (! isset($ec['x'], $ec['y']) || ! is_string($ec['x']) || ! is_string($ec['y'])) {
            throw new InvalidArgumentException('Fixture EC key is missing coordinates.');
        }

        $u2fPublicKey = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $keypair = [
            'private_key_pem' => $privateKeyPem,
            'cose_public_key' => U2FPublicKey::convertToCoseKey($u2fPublicKey),
        ];

        return $keypair;
    }

    /**
     * Create a stored credential record.
     */
    protected function createCredentialSource(string $userHandle, ?string $credentialId = null, int $counter = 0): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: $credentialId ?? random_bytes(16),
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: $this->fixtureEcKeypair()['cose_public_key'],
            userHandle: $userHandle,
            counter: $counter,
        );
    }

    /**
     * Create a signed assertion response.
     */
    protected function createSignedAssertionResponse(
        string $challenge,
        string $origin,
        int $signCount,
        ?string $rpId = null,
    ): AuthenticatorAssertionResponse {
        $clientDataPayload = [
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
        ];

        $clientDataRaw = json_encode($clientDataPayload, JSON_THROW_ON_ERROR);
        $clientData = CollectedClientData::create($clientDataRaw, $clientDataPayload);

        $rpId ??= parse_url($origin, PHP_URL_HOST);

        if (! is_string($rpId) || $rpId === '') {
            throw new InvalidArgumentException('Unable to determine RP ID for assertion response fixture.');
        }

        $rpIdHash = hash('sha256', $rpId, binary: true);
        $flags = chr(AuthenticatorData::FLAG_UP);
        $authDataRaw = $rpIdHash . $flags . pack('N', $signCount);

        $authenticatorData = AuthenticatorData::create(
            authData: $authDataRaw,
            rpIdHash: $rpIdHash,
            flags: $flags,
            signCount: $signCount,
        );

        $payload = $authDataRaw . hash('sha256', $clientDataRaw, binary: true);
        $signature = '';
        $keypair = $this->fixtureEcKeypair();
        openssl_sign($payload, $signature, $keypair['private_key_pem'], OPENSSL_ALGO_SHA256);

        return AuthenticatorAssertionResponse::create(
            clientDataJSON: $clientData,
            authenticatorData: $authenticatorData,
            signature: $signature,
            userHandle: null,
        );
    }

    /**
     * Create registration options.
     */
    protected function createRegistrationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: 'localhost'),
            user: PublicKeyCredentialUserEntity::create($user->email, $user->getPasskeyUserHandle(), $user->name),
            challenge: random_bytes(32),
        );
    }

    /**
     * Create request options.
     */
    protected function createRequestOptions(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        );
    }

    /**
     * Create a structurally valid assertion credential array for HTTP tests.
     *
     * @return array<string, mixed>
     */
    protected function createAssertionCredential(): array
    {
        $rawId = random_bytes(16);

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
            'origin' => 'https://localhost',
        ], JSON_THROW_ON_ERROR);

        $rpIdHash = hash('sha256', 'localhost', binary: true);
        $flags = chr(AuthenticatorData::FLAG_UP);
        $authData = $rpIdHash . $flags . pack('N', 0);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($rawId),
            'type' => 'public-key',
            'rawId' => Base64UrlSafe::encodeUnpadded($rawId),
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded(random_bytes(64)),
            ],
        ];
    }
}
