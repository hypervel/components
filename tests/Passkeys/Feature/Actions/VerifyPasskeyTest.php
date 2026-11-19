<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Events\PasskeyVerified;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use UnitEnum;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorResponse;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

class VerifyPasskeyTest extends TestCase
{
    use VerifiesDoubles;
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
            response: Double::for(AuthenticatorAssertionResponse::class)->strict(),
        );

        $options = $this->createRequestOptions();

        $updatedSource = $this->createCredentialSource($userHandle, $credentialId, counter: 6);

        $action = Double::for(new VerifyPasskey)->passthru();
        $action->expects('validate')->returns($updatedSource);

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
            response: Double::for(AuthenticatorAttestationResponse::class)->strict(),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIsOrContains('Unable to verify passkey');

        app(VerifyPasskey::class)($assertion, $this->createRequestOptions());
    }

    public function testItThrowsExceptionWhenPasskeyIsNotFound(): void
    {
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: Double::for(AuthenticatorAssertionResponse::class)->strict(),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIsOrContains('Passkey not recognized');

        app(VerifyPasskey::class)($assertion, $this->createRequestOptions());
    }

    public function testItRejectsOversizedCredentialIdsBeforeQuerying(): void
    {
        $response = Double::for(AuthenticatorAssertionResponse::class)->strict();
        $action = app(VerifyPasskey::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $action->getPasskey(PublicKeyCredential::create('public-key', str_repeat('a', 1024), $response));
            $this->fail('Expected an oversized credential ID to be rejected.');
        } catch (InvalidPasskeyException) {
        }

        $this->assertSame([], DB::getQueryLog());

        try {
            $action->getPasskey(PublicKeyCredential::create('public-key', random_bytes(16), $response));
            $this->fail('Expected a missing credential ID to be rejected.');
        } catch (InvalidPasskeyException) {
        }

        $this->assertNotSame([], DB::getQueryLog());
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
            response: Double::for(AuthenticatorAssertionResponse::class)->strict(),
        );

        $action = Double::for(new VerifyPasskey)->passthru();
        $action->expects('validate')->never();

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIsOrContains('Passkey not recognized');

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

    public function testItVerifiesALegacyPasskeyWhoseStoredUserHandleIsRawBinary(): void
    {
        config()->set('passkeys.allowed_origins', ['https://localhost']);
        config()->set('passkeys.relying_party_id', 'localhost');

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $credentialId = random_bytes(16);

        // Credentials registered before the handle became hex encoded stored raw
        // HMAC bytes. Verification compares against the stored value, so they
        // must keep working when the authenticator returns the handle intact.
        $binaryHandle = hash_hmac('sha256', User::class . '|users|' . $user->id, 'legacy-secret', binary: true);

        $passkey = $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(
                WebAuthn::toJson($this->createCredentialSource($binaryHandle, $credentialId)),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        ]);

        $options = $this->createRequestOptions();
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $this->createSignedAssertionResponse($options->challenge, 'https://localhost', signCount: 1, rpId: 'localhost', userHandle: $binaryHandle),
        );

        $this->assertSame($passkey->id, app(VerifyPasskey::class)($assertion, $options)->id);
    }

    public function testItRejectsAnAssertionWhoseUserHandleNoLongerMatchesTheStoredCredential(): void
    {
        config()->set('passkeys.allowed_origins', ['https://localhost']);
        config()->set('passkeys.relying_party_id', 'localhost');

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $credentialId = random_bytes(16);
        $binaryHandle = hash_hmac('sha256', User::class . '|users|' . $user->id, 'legacy-secret', binary: true);

        $user->passkeys()->create([
            'name' => 'My iPhone',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(
                WebAuthn::toJson($this->createCredentialSource($binaryHandle, $credentialId)),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        ]);

        // iOS Safari decodes binary handles as UTF-8 with replacement, so the
        // handle comes back full of U+FFFD bytes and no longer matches.
        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $corruptedHandle = mb_convert_encoding($binaryHandle, 'UTF-8', 'UTF-8');
        mb_substitute_character($substitute);

        $options = $this->createRequestOptions();
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $this->createSignedAssertionResponse($options->challenge, 'https://localhost', signCount: 1, rpId: 'localhost', userHandle: $corruptedHandle),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIs('This passkey can no longer be used. Please remove it from your account and register a new one.');

        app(VerifyPasskey::class)($assertion, $options);
    }

    public function testItRejectsAnAssertionSignedForADifferentChallenge(): void
    {
        [, $credentialId] = $this->createStoredPasskeyCredential();

        // Starting a login in a second tab replaces the options in the session,
        // so the first tab's assertion carries a challenge the server no longer expects.
        $options = $this->createRequestOptions();
        $staleOptions = $this->createRequestOptions();

        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $this->createSignedAssertionResponse($staleOptions->challenge, 'https://localhost', signCount: 1, rpId: 'localhost'),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIs('Unable to verify passkey. Please try again.');

        app(VerifyPasskey::class)($assertion, $options);
    }

    public function testItConvertsCounterRejectionsIntoInvalidPasskeyException(): void
    {
        [$user, $credentialId, $source] = $this->createStoredPasskeyCredential(counter: 5);
        $options = $this->createRequestOptions();
        $response = $this->createSignedAssertionResponse(
            $options->challenge,
            'https://localhost',
            signCount: 5,
            rpId: 'localhost',
        );
        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $response,
        );

        try {
            WebAuthn::assertionValidator()->check(
                credentialRecord: $source,
                authenticatorAssertionResponse: $response,
                publicKeyCredentialRequestOptions: $options,
                host: 'localhost',
                userHandle: $source->userHandle,
            );
            $this->fail('Expected the unchanged authenticator counter to be rejected.');
        } catch (CounterException) {
        }

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIs('Unable to verify passkey. Please try again.');

        app(VerifyPasskey::class)($credential, $options, $user);
    }

    public function testItDoesNotConvertWebAuthnFactoryFailures(): void
    {
        [$user, $credentialId] = $this->createStoredPasskeyCredential();
        $options = $this->createRequestOptions();
        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $this->createSignedAssertionResponse(
                $options->challenge,
                'https://localhost',
                signCount: 1,
                rpId: 'localhost',
            ),
        );

        WebAuthn::configureCeremonyStepManagerFactoryUsing(
            static function (CeremonyStepManagerFactory $factory): never {
                throw new RuntimeException('Unable to configure the ceremony factory.');
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to configure the ceremony factory.');

        app(VerifyPasskey::class)($credential, $options, $user);
    }

    public function testItUsesTheRelyingPartyIdStoredInVerificationOptions(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'registered.example.com',
        );

        $action = new ExposesVerifyPasskeyHost;

        $this->assertSame('registered.example.com', $action->host($options));
    }

    public function testItThrowsWhenVerificationOptionsHaveNoRelyingPartyId(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
        );

        $action = new ExposesVerifyPasskeyHost;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Passkey verification options must contain a relying party ID.');

        $action->host($options);
    }

    #[WithConfig('database.connections.passkeys', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => false,
    ])]
    public function testItUsesTheConfiguredPasskeyModelConnectionForVerificationTransactions(): void
    {
        Passkeys::usePasskeyModel(CustomConnectionPasskey::class);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = new CustomConnectionPasskey;
        $passkey->forceFill([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'name' => 'Laptop',
            'credential_id' => 'credential-connection',
            'credential' => ['id' => 'credential-connection'],
        ]);

        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(false);
        $this->instance('events', $events);

        $credential = PublicKeyCredential::create(
            'public-key',
            random_bytes(32),
            $this->createStub(AuthenticatorResponse::class),
        );

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        );

        $verifier = new ConnectionAwareVerifyPasskey(
            $passkey,
            $this->createStub(AuthenticatorAssertionResponse::class),
        );

        $this->assertSame($passkey, $verifier($credential, $options, $user));
        $this->assertTrue($verifier->receivedLockedLookup);
        $this->assertSame(1, $verifier->lookupTransactionLevel);
        $events->received('hasListeners')->with(PasskeyVerified::class)->times(1);
        $events->received('dispatch')->with(Argument::type(PasskeyVerified::class))->never();
    }

    /**
     * Create a stored passkey credential that can reach the WebAuthn validator.
     *
     * @return array{User, string, CredentialRecord}
     */
    private function createStoredPasskeyCredential(int $counter = 0): array
    {
        config()->set('passkeys.allowed_origins', ['https://localhost']);
        config()->set('passkeys.relying_party_id', 'localhost');

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $credentialId = random_bytes(16);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle(), $credentialId, $counter);

        $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);

        return [$user, $credentialId, $source];
    }
}

class CustomConnectionPasskey extends Passkey
{
    protected UnitEnum|string|null $connection = 'passkeys';
}

class ExposesVerifyPasskeyHost extends VerifyPasskey
{
    /**
     * Get the relying party ID stored in the verification options.
     */
    public function host(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->hostFromOptions($options);
    }
}

class ConnectionAwareVerifyPasskey extends VerifyPasskey
{
    public bool $receivedLockedLookup = false;

    public ?int $lookupTransactionLevel = null;

    public function __construct(
        private readonly Passkey $passkey,
        private readonly AuthenticatorAssertionResponse $response,
    ) {
    }

    /**
     * Get the authenticator assertion response from the credential.
     */
    protected function getResponse(PublicKeyCredential $credential): AuthenticatorAssertionResponse
    {
        return $this->response;
    }

    /**
     * Get the passkey by credential ID.
     */
    public function getPasskey(PublicKeyCredential $credential, bool $lock = false): Passkey
    {
        $this->receivedLockedLookup = $lock;
        $this->lookupTransactionLevel = DB::connection('passkeys')->transactionLevel();

        return $this->passkey;
    }

    /**
     * Validate the credential against the stored passkey.
     */
    protected function validate(
        AuthenticatorAssertionResponse $response,
        Passkey $passkey,
        PublicKeyCredentialRequestOptions $options
    ): CredentialRecord {
        return CredentialRecord::create(
            publicKeyCredentialId: random_bytes(32),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: random_bytes(77),
            userHandle: 'test-user-handle',
            counter: 0,
        );
    }

    /**
     * Update the passkey with the latest credential data.
     */
    public function updatePasskey(Passkey $passkey, CredentialRecord $source): void
    {
    }
}
