<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Events\PasskeyVerified;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;

class VerifyPasskeyTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItVerifiesAPasskeyAndReturnsIt(): void
    {
        Event::fake([PasskeyVerified::class]);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $credentialId = random_bytes(16);
        $userHandle = $user->getPasskeyUserHandle();

        $source = $this->createCredentialSource($userHandle, $credentialId, counter: 5);

        $passkey = $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);

        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: m::mock(AuthenticatorAssertionResponse::class),
        );

        $options = $this->createRequestOptions();

        $updatedSource = $this->createCredentialSource($userHandle, $credentialId, counter: 6);

        $action = m::mock(VerifyPasskey::class, [
            app(ConnectionResolverInterface::class),
            app(Dispatcher::class),
        ])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->once()
            ->andReturn($updatedSource)
            ->getMock();

        $result = $action($assertion, $options);

        $this->assertInstanceOf(Passkey::class, $result);
        $this->assertSame($passkey->id, $result->id);
        $this->assertNotNull($result->last_used_at);

        $result->refresh();
        $this->assertSame(6, $result->credential['counter']);

        Event::assertDispatched(
            PasskeyVerified::class,
            static fn (PasskeyVerified $event): bool => $event->user->is($user)
                && $event->passkey->is($passkey),
        );
    }

    public function testItThrowsExceptionWhenResponseIsNotAnAssertionResponse(): void
    {
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: 'test-raw-id',
            response: m::mock(AuthenticatorAttestationResponse::class),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to verify passkey');

        app(VerifyPasskey::class)($assertion, $this->createRequestOptions());
    }

    public function testItThrowsExceptionWhenPasskeyIsNotFound(): void
    {
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: m::mock(AuthenticatorAssertionResponse::class),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Passkey not recognized');

        app(VerifyPasskey::class)($assertion, $this->createRequestOptions());
    }

    public function testItThrowsExceptionWhenPasskeyDoesNotBelongToExpectedUser(): void
    {
        $owner = User::create([
            'name' => 'Passkey Owner',
            'email' => 'owner@example.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        $credentialId = random_bytes(16);
        $userHandle = $owner->getPasskeyUserHandle();
        $source = $this->createCredentialSource($userHandle, $credentialId, counter: 1);

        $owner->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);

        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: m::mock(AuthenticatorAssertionResponse::class),
        );

        $action = m::mock(VerifyPasskey::class, [
            app(ConnectionResolverInterface::class),
            app(Dispatcher::class),
        ])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->never()
            ->getMock();

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Passkey not recognized');

        $action($assertion, $this->createRequestOptions(), $otherUser);
    }

    public function testItVerifiesAnExistingPasskeyAfterUserHandleSecretRotation(): void
    {
        config()->set('passkeys.allowed_origins', ['https://localhost']);
        config()->set('passkeys.relying_party_id', 'localhost');
        config()->set('passkeys.user_handle_secret', 'initial-user-handle-secret');

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $credentialId = random_bytes(16);
        $initialUserHandle = $user->getPasskeyUserHandle();

        $passkey = $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(
                WebAuthn::toJson($this->createCredentialSource($initialUserHandle, $credentialId)),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        ]);

        config()->set('passkeys.user_handle_secret', 'rotated-user-handle-secret');

        $options = $this->createRequestOptions();
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $this->createSignedAssertionResponse($options->challenge, 'https://localhost', signCount: 6, rpId: 'localhost'),
        );

        $result = app(VerifyPasskey::class)($assertion, $options, $user);

        $this->assertSame($passkey->id, $result->id);
        $this->assertSame($initialUserHandle, Base64UrlSafe::decodeNoPadding($result->refresh()->credential['userHandle']));
    }
}
