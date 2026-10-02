<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Tests\Passkeys\Fixtures\Admin;
use Hypervel\Tests\Passkeys\Fixtures\User;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeysGuardTest extends TestCase
{
    public function testPasskeysGuardFollowsCurrentDefaultGuardSelectedByShouldUse(): void
    {
        $this->configureAdminGuard();

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->shouldUse('admin');

        $this->assertSame('admin', Passkeys::guardName());
        $this->assertSame($auth->guard('admin'), Passkeys::guard());
        $this->assertInstanceOf(StatefulGuard::class, Passkeys::guard());
    }

    #[DataProvider('ownerTypesOutsideTheSelectedProvider')]
    public function testSelectedGuardProviderScopesPasswordlessPasskeyVerification(string $ownerType): void
    {
        $this->configureAdminGuard();

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);
        $auth->shouldUse('admin');

        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
        ]);

        $rawCredentialId = random_bytes(32);
        $credentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);

        (new Passkey)->forceFill([
            'user_type' => $ownerType,
            'user_id' => $user->getKey(),
            'name' => 'User key',
            'credential_id' => $credentialId,
            'credential' => ['id' => $credentialId],
        ])->save();

        $credential = PublicKeyCredential::create(
            'public-key',
            $rawCredentialId,
            $this->createStub(AuthenticatorAssertionResponse::class),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Passkey not recognized. It may have been removed from your account.');

        app(VerifyPasskey::class)($credential, PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        ));
    }

    /**
     * Get stored owner types that the admin guard provider does not own.
     *
     * @return array<string, array{string}>
     */
    public static function ownerTypesOutsideTheSelectedProvider(): array
    {
        return [
            'another provider model' => [User::class],
            'unresolvable owner type' => ['Missing\PasskeyOwner'],
        ];
    }

    /**
     * Configure the admin guard fixture.
     */
    private function configureAdminGuard(): void
    {
        config()->set([
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'admins',
                'passwords' => null,
                'password_timeout' => null,
                'remember' => null,
            ],
            'auth.providers.admins' => [
                'driver' => 'eloquent',
                'model' => Admin::class,
                'cache' => [
                    'enabled' => false,
                    'store' => null,
                    'ttl' => 300,
                    'prefix' => EloquentUserProvider::DEFAULT_CACHE_PREFIX,
                    'tags' => null,
                ],
            ],
        ]);
    }
}
