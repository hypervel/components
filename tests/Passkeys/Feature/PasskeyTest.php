<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\PasskeyTest;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\PasskeyAuthenticatable;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Passkeys\TestCase;

class PasskeyTest extends TestCase
{
    public function testItCanInstantiateThePasskeyModel(): void
    {
        $passkey = new Passkey;

        $this->assertInstanceOf(Passkey::class, $passkey);
    }

    public function testItHasTheCorrectFillableAttributes(): void
    {
        $passkey = new Passkey;

        $this->assertSame([
            'name',
            'credential_id',
            'credential',
        ], $passkey->getFillable());
    }

    public function testItCanBelongToAUserModelWithACustomPrimaryKey(): void
    {
        Schema::create('users_with_custom_primary_keys', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
        });

        $user = UserWithCustomPrimaryKey::create([
            'user_id' => 1234,
        ]);

        $passkey = (new Passkey)->forceFill([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
        ]);

        $this->assertNotNull($passkey->user);
        $this->assertTrue($passkey->user->is($user));
    }
}

class UserWithCustomPrimaryKey extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;

    protected ?string $table = 'users_with_custom_primary_keys';

    protected string $primaryKey = 'user_id';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $guarded = [];
}
