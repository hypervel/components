<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passkeys\Actions\StorePasskey;
use Hypervel\Passkeys\Events\PasskeyRegistered;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class StorePasskeyTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItStoresAPasskeyForTheUser(): void
    {
        Event::fake([PasskeyRegistered::class]);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $attestationResponse = m::mock(AuthenticatorAttestationResponse::class);

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: $attestationResponse,
        );

        $options = $this->createRegistrationOptions($user);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle());

        $action = m::mock(StorePasskey::class, [app(Dispatcher::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->once()
            ->andReturn($source)
            ->getMock();

        $passkey = $action($user, 'My MacBook', $credential, $options);

        $this->assertInstanceOf(Passkey::class, $passkey);
        $this->assertSame('My MacBook', $passkey->name);
        $this->assertSame($user->getMorphClass(), $passkey->user_type);
        $this->assertSame($user->getKey(), $passkey->user_id);
        $this->assertTrue($passkey->user->is($user));
        $this->assertSame(1, $user->passkeys()->count());

        Event::assertDispatched(
            PasskeyRegistered::class,
            static fn (PasskeyRegistered $event): bool => $event->user->is($user)
                && $event->passkey->is($passkey),
        );
    }

    public function testItThrowsExceptionWhenCredentialIsAlreadyRegistered(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $credentialId = random_bytes(16);

        $user->passkeys()->create([
            'name' => 'Existing Passkey',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => ['test' => 'data'],
        ]);

        $attestationResponse = m::mock(AuthenticatorAttestationResponse::class);

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $attestationResponse,
        );

        $options = $this->createRegistrationOptions($user);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle(), $credentialId);

        $action = m::mock(StorePasskey::class, [app(Dispatcher::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->once()
            ->andReturn($source)
            ->getMock();

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register this passkey.');

        $action($user, 'Duplicate Passkey', $credential, $options);
    }

    public function testItConvertsDuplicateCredentialInsertRaceIntoInvalidPasskeyException(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle(), random_bytes(16));
        $action = app(StorePasskey::class);

        $action->createPasskey($user, 'Laptop', $source);

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register this passkey.');

        $action->createPasskey($user, 'Laptop again', $source);
    }

    public function testItThrowsExceptionWhenResponseIsNotAnAttestationResponse(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $assertionResponse = m::mock(AuthenticatorAssertionResponse::class);

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: 'test-raw-id',
            response: $assertionResponse,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: 'localhost'),
            user: PublicKeyCredentialUserEntity::create('test', '1', 'Test'),
            challenge: random_bytes(32),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register passkey');

        app(StorePasskey::class)($user, 'Test Passkey', $credential, $options);
    }
}
