<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Hypervel\Validation\ValidationException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class PasskeyLoginControllerTest extends TestCase
{
    use VerifiesDoubles;
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

        $action = Double::for(VerifyPasskey::class)->strict();
        $action
            ->expects('__invoke')
            ->returns($passkey);
        $this->instance(VerifyPasskey::class, $action);

        Passkeys::authorizeLoginUsing(static fn (): bool => false);

        $this->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', ['credential' => $this->createAssertionCredential()])
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function testItReturnsTheCustomSignInAuthorizationValidationMessage(): void
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

        $action = Double::for(VerifyPasskey::class)->strict();
        $action
            ->expects('__invoke')
            ->returns($passkey);
        $this->instance(VerifyPasskey::class, $action);

        Passkeys::authorizeLoginUsing(static function (): never {
            throw ValidationException::withMessages([
                'credential' => ['This account has been suspended.'],
            ]);
        });

        $this->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', ['credential' => $this->createAssertionCredential()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'This account has been suspended.']);

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

        $action = Double::for(VerifyPasskey::class)->strict();
        $action
            ->expects('__invoke')
            ->returns($passkey);
        $this->instance(VerifyPasskey::class, $action);

        Passkeys::authorizeLoginUsing(static fn (): bool => true);

        $this->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
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
