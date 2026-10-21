<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passkeys\Actions\DeletePasskey;
use Hypervel\Passkeys\Events\PasskeyDeleted;
use Hypervel\Passkeys\Passkey;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;

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

    public function testItDeletesAnotherUsersPasskeyForAnActorThatDoesNotOwnPasskeys(): void
    {
        Event::fake([PasskeyDeleted::class]);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $passkey = $this->createPasskeyForUser($user, 'credential-admin-delete');
        $administrator = new GenericUser(['id' => 99]);

        app(DeletePasskey::class)($administrator, $passkey);

        $this->assertDatabaseMissing('passkeys', [
            'id' => $passkey->getKey(),
        ]);
        Event::assertDispatched(
            PasskeyDeleted::class,
            static fn (PasskeyDeleted $event): bool => $event->user === $administrator
                && $event->passkey->is($passkey),
        );
    }

    public function testItDoesNotDispatchPasskeyDeletedEventWithoutListeners(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $passkey = $this->createPasskeyForUser($user, 'credential-quiet-delete');

        $events = m::mock(Dispatcher::class)->shouldIgnoreMissing();
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnFalse()->byDefault();
        $events->shouldReceive('hasListeners')->once()->with(PasskeyDeleted::class)->andReturnFalse();
        $events->shouldReceive('dispatch')
            ->withArgs(static fn (mixed $event): bool => $event instanceof PasskeyDeleted)
            ->never();

        $this->instance('events', $events);

        app(DeletePasskey::class)($user, $passkey);

        $this->assertDatabaseMissing('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    /**
     * Create a passkey for the given user.
     */
    private function createPasskeyForUser(User $user, string $credentialId): Passkey
    {
        /** @var Passkey $passkey */
        return $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => $credentialId,
            'credential' => ['id' => $credentialId],
        ]);
    }
}
