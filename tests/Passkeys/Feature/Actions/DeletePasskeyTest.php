<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Passkeys\Actions\DeletePasskey;
use Hypervel\Passkeys\Events\PasskeyDeleted;
use Hypervel\Passkeys\Passkey;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;

class DeletePasskeyTest extends TestCase
{
    public function testItDeletesThePasskey(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test'],
        ]);

        app(DeletePasskey::class)($user, $passkey);

        $this->assertNull(Passkey::find($passkey->id));
    }

    public function testItDispatchesPasskeyDeletedEvent(): void
    {
        Event::fake([PasskeyDeleted::class]);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test'],
        ]);

        app(DeletePasskey::class)($user, $passkey);

        Event::assertDispatched(
            PasskeyDeleted::class,
            static fn (PasskeyDeleted $event): bool => $event->user->is($user)
                && $event->passkey->is($passkey),
        );
    }
}
