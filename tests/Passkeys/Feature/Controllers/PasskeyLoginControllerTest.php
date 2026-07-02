<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;

class PasskeyLoginControllerTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItDoesNotLogInWhenCustomSignInAuthorizationCallbackReturnsFalse(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => 'test-credential-id',
            'credential' => [],
        ]);

        $this->instance(VerifyPasskey::class, m::mock(VerifyPasskey::class)
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn($passkey)
            ->getMock());

        Passkeys::authorizeLoginUsing(static fn (): bool => false);

        $this->withSession(['passkey.login_options' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', ['credential' => $this->createAssertionCredential()])
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function testItLogsInWhenCustomSignInAuthorizationCallbackReturnsTrue(): void
    {
        $user = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'My iPhone',
            'credential_id' => 'test-credential-id-2',
            'credential' => [],
        ]);

        $this->instance(VerifyPasskey::class, m::mock(VerifyPasskey::class)
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn($passkey)
            ->getMock());

        Passkeys::authorizeLoginUsing(static fn (): bool => true);

        $this->withSession(['passkey.login_options' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', [
                'credential' => $this->createAssertionCredential(),
                'remember' => true,
            ])
            ->assertOk()
            ->assertJsonStructure(['redirect'])
            ->assertJsonMissing(['verified']);

        $this->assertAuthenticatedAs($user);
    }
}
