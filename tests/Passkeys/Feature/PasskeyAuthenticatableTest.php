<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\PasskeyAuthenticatableTest;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\PasskeyAuthenticatable;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;

class PasskeyAuthenticatableTest extends TestCase
{
    public function testItUsesTheNameColumnForTheDisplayName(): void
    {
        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);

        $this->assertSame('Alex Müller', $user->getPasskeyDisplayName());
    }

    public function testItUsesTheEmailColumnForTheUsername(): void
    {
        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);

        $this->assertSame('alex@example.com', $user->getPasskeyUsername());
    }

    public function testItFallsBackToEmailForDisplayNameWhenNameIsAbsent(): void
    {
        Schema::create('minimal_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        $user = MinimalUser::create(['email' => 'only@example.com']);

        $this->assertSame('only@example.com', $user->getPasskeyDisplayName());
        $this->assertSame('only@example.com', $user->getPasskeyUsername());
    }

    public function testItFallsBackToTheAuthIdentifierWhenEmailIsAbsent(): void
    {
        Schema::create('bare_users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $user = BareUser::create();

        $this->assertSame((string) $user->id, $user->getPasskeyDisplayName());
        $this->assertSame((string) $user->id, $user->getPasskeyUsername());
    }

    public function testItReturnsTheSameHandleAcrossFreshModelInstances(): void
    {
        config(['passkeys.user_handle_secret' => 'test-secret']);

        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);
        $handle = $user->getPasskeyUserHandle();

        $this->assertSame(
            hash_hmac('sha256', 'users|' . $user->getKey(), 'test-secret', binary: true),
            $handle,
        );
        $this->assertNotSame((string) $user->getKey(), $handle);
        $this->assertSame($handle, User::find($user->id)->getPasskeyUserHandle());
        $this->assertSame(32, strlen($handle));
    }

    public function testItDoesNotChangeWhenNonIdentifyingAttributesChange(): void
    {
        config(['passkeys.user_handle_secret' => 'test-secret']);

        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);
        $before = $user->getPasskeyUserHandle();

        $user->update(['name' => 'Alexandra', 'email' => 'new@example.com']);

        $this->assertSame($before, $user->fresh()->getPasskeyUserHandle());
    }

    public function testItChangesWhenTheSecretRotates(): void
    {
        config(['passkeys.user_handle_secret' => 'secret-a']);

        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);
        $before = $user->getPasskeyUserHandle();

        config(['passkeys.user_handle_secret' => 'secret-b']);

        $this->assertNotSame($before, $user->getPasskeyUserHandle());
    }

    public function testItUsesDistinctUserHandlesForDifferentUsers(): void
    {
        config(['passkeys.user_handle_secret' => 'test-secret']);

        $one = User::create(['name' => 'One', 'email' => 'one@example.com']);
        $two = User::create(['name' => 'Two', 'email' => 'two@example.com']);

        $this->assertNotSame($one->getPasskeyUserHandle(), $two->getPasskeyUserHandle());
    }

    public function testItIncludesTheModelTableWhenDerivingUserHandles(): void
    {
        config(['passkeys.user_handle_secret' => 'test-secret']);

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);

        $admin = AdminUser::create();

        $this->assertNotSame($user->getPasskeyUserHandle(), $admin->getPasskeyUserHandle());
    }

    public function testDeletingOwnerDeletesRelatedPasskeys(): void
    {
        $user = User::create([
            'name' => 'Alex Müller',
            'email' => 'alex@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-owner-delete');

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
            'user_type' => $user->getMorphClass(),
            'user_id' => (string) $user->getKey(),
        ]);

        $user->delete();

        $this->assertSame(0, Passkey::query()->count());
    }

    public function testSoftDeletingOwnerPreservesRelatedPasskeys(): void
    {
        $this->createSoftDeletingUsersTable();

        $user = SoftDeletingPasskeyUser::create([
            'name' => 'Soft Delete',
            'email' => 'soft@example.com',
        ]);

        $passkey = $this->createPasskeyForUser($user, 'credential-soft-delete');

        $user->delete();

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    public function testForceDeletingOwnerDeletesRelatedPasskeys(): void
    {
        $this->createSoftDeletingUsersTable();

        $user = SoftDeletingPasskeyUser::create([
            'name' => 'Force Delete',
            'email' => 'force@example.com',
        ]);

        $this->createPasskeyForUser($user, 'credential-force-delete');

        $user->forceDelete();

        $this->assertSame(0, Passkey::query()->count());
    }

    /**
     * Create a passkey for the given user.
     */
    private function createPasskeyForUser(PasskeyUser $user, string $credentialId): Passkey
    {
        /** @var Passkey $passkey */
        return $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => $credentialId,
            'credential' => ['id' => $credentialId],
        ]);
    }

    /**
     * Create the soft-deleting users fixture table.
     */
    private function createSoftDeletingUsersTable(): void
    {
        Schema::create('soft_deleting_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

class MinimalUser extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;

    protected ?string $table = 'minimal_users';

    protected array $guarded = [];
}

class BareUser extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;

    protected ?string $table = 'bare_users';

    protected array $guarded = [];
}

class AdminUser extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;

    protected ?string $table = 'admin_users';

    protected array $guarded = [];
}

class SoftDeletingPasskeyUser extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;
    use SoftDeletes;

    protected ?string $table = 'soft_deleting_users';

    protected array $guarded = [];
}
