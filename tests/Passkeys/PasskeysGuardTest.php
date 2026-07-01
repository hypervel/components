<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Passkeys\Fixtures\Admin;
use Hypervel\Tests\Passkeys\Fixtures\User;
use ParagonIE\ConstantTime\Base64UrlSafe;
use ReflectionMethod;
use Webauthn\AuthenticatorResponse;
use Webauthn\PublicKeyCredential;

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

    public function testSelectedGuardProviderScopesPasswordlessPasskeyLookup(): void
    {
        $this->configureAdminGuard();
        $this->createAdminsTable();

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);
        $auth->shouldUse('admin');

        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
        ]);
        $admin = Admin::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $rawCredentialId = random_bytes(32);
        $credentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);

        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->create([
            'name' => 'User key',
            'credential_id' => $credentialId,
            'credential' => ['id' => $credentialId],
        ]);

        $credential = PublicKeyCredential::create(
            'public-key',
            $rawCredentialId,
            $this->createStub(AuthenticatorResponse::class),
        );

        $verifier = new VerifyPasskey(
            $this->app->make('db'),
            $this->app->make(Dispatcher::class),
        );
        $selectedOwnerMorphClass = $this->selectedOwnerMorphClass($verifier);

        $this->assertSame($admin->getMorphClass(), $selectedOwnerMorphClass);
        $this->assertNotSame($passkey->user_type, $selectedOwnerMorphClass);

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Passkey not recognized. It may have been removed from your account.');

        $verifier->getPasskey($credential, ownerType: $selectedOwnerMorphClass);
    }

    /**
     * Configure the admin guard fixture.
     */
    private function configureAdminGuard(): void
    {
        config()->set([
            'auth.guards.admin' => ['driver' => 'session', 'provider' => 'admins'],
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => Admin::class],
        ]);
    }

    /**
     * Create the admins table fixture.
     */
    private function createAdminsTable(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Get the owner morph class for the selected guard.
     */
    private function selectedOwnerMorphClass(VerifyPasskey $verifier): string
    {
        $method = new ReflectionMethod($verifier, 'ownerMorphClassForGuard');
        $method->setAccessible(true);

        return $method->invoke($verifier, Passkeys::guard());
    }
}
