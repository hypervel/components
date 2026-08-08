<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passkeys\Database;

use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredential;

abstract class PasskeyCredentialIdTestCase extends DatabaseTestCase
{
    use WebAuthnFixtures;

    public function testMaximumCredentialIdPersistsAndLooksUpExactly(): void
    {
        $rawId = str_repeat("\xff", 1023);
        $credentialId = Base64UrlSafe::encodeUnpadded($rawId);
        $source = $this->createCredentialSource('test-user-handle', $rawId);
        $passkey = $this->createPasskey(
            $credentialId,
            json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        );

        $this->assertSame(1364, strlen($credentialId));
        $this->assertSame($credentialId, $passkey->refresh()->credential_id);
        $this->assertSame(
            $passkey->getKey(),
            Passkey::query()->where('credential_id', $credentialId)->firstOrFail()->getKey(),
        );

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $rawId,
            response: $this->createStub(AuthenticatorAssertionResponse::class),
        );

        $this->assertSame($passkey->getKey(), app(VerifyPasskey::class)->getPasskey($credential)->getKey());
    }

    public function testExactDuplicateCredentialIdIsRejected(): void
    {
        $credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(16));

        $this->createPasskey($credentialId);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createPasskey($credentialId);
    }

    public function testCredentialIdUniquenessIsCaseSensitive(): void
    {
        // These valid Base64URL values differ only by letter case.
        $first = $this->createPasskey('Abcd');
        $second = $this->createPasskey('abcd');

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(['Abcd', 'abcd'], Passkey::query()->orderBy('id')->pluck('credential_id')->all());
    }

    /**
     * Get the migration options for the package schema.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => false,
            '--realpath' => true,
            '--path' => [Passkeys::migrationPath()],
        ];
    }

    /**
     * Create a passkey record without requiring an owner table.
     *
     * @param array<string, mixed> $credential
     */
    protected function createPasskey(string $credentialId, array $credential = []): Passkey
    {
        $passkey = new Passkey;
        $passkey->forceFill([
            'user_type' => 'test-users',
            'user_id' => 1,
            'name' => 'Test passkey',
            'credential_id' => $credentialId,
            'credential' => $credential,
        ])->save();

        return $passkey;
    }
}
