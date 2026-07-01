<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passkeys\Actions\DeletePasskey;
use Hypervel\Passkeys\Events\PasskeyDeleted;
use Hypervel\Passkeys\Passkey;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    public function testItRejectsDeletingAnotherUsersPasskey(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $otherUser = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
        $passkey = $this->createPasskeyForUser($user, 'credential-other-owner');

        try {
            app(DeletePasskey::class)($otherUser, $passkey);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseHas('passkeys', [
                'id' => $passkey->getKey(),
            ]);

            return;
        }

        $this->fail('Expected deleting another user\'s passkey to fail.');
    }

    public function testItRejectsDeletingAPasskeyForTheSameKeyOnADifferentOwnerMorphClass(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $sameKeyDifferentMorphUser = AlternatePasskeyUser::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
        $passkey = $this->createPasskeyForUser($user, 'credential-other-morph');

        $sameKeyDifferentMorphUser->forceFill([
            $sameKeyDifferentMorphUser->getKeyName() => $user->getKey(),
        ]);

        try {
            app(DeletePasskey::class)($sameKeyDifferentMorphUser, $passkey);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseHas('passkeys', [
                'id' => $passkey->getKey(),
            ]);

            return;
        }

        $this->fail('Expected deleting a passkey for a different owner morph class to fail.');
    }

    public function testItDoesNotDispatchPasskeyDeletedEventWithoutListeners(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $passkey = $this->createPasskeyForUser($user, 'credential-quiet-delete');

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(PasskeyDeleted::class)->andReturnFalse();
        $events->shouldReceive('dispatch')->never();

        (new DeletePasskey($events))($user, $passkey);

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

class AlternatePasskeyUser extends User
{
    protected ?string $table = 'users';
}
