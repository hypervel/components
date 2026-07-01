<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\PasskeyAuthenticatableTest;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passkeys\Contracts\PasskeyUser;
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
