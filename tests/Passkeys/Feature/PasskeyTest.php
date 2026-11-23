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

    public function testItOnlyAppendsTheAuthenticatorLabelByDefault(): void
    {
        $passkey = new Passkey;

        $this->assertSame(['authenticator'], $passkey->getAppends());
    }

    public function testItOmitsAuthenticatorIconsFromSerializationByDefault(): void
    {
        $passkey = new Passkey([
            'name' => 'Test',
            'credential_id' => 'cred',
            'credential' => ['aaguid' => 'a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942'],
        ]);

        $array = $passkey->toArray();

        $this->assertSame('AliasVault', $array['authenticator']);
        $this->assertArrayNotHasKey('authenticator_icon_light', $array);
        $this->assertArrayNotHasKey('authenticator_icon_dark', $array);
    }

    public function testItExposesAuthenticatorIconsViaDirectAccessorWithoutSerialization(): void
    {
        $passkey = new Passkey([
            'name' => 'Test',
            'credential_id' => 'cred',
            'credential' => ['aaguid' => 'a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942'],
        ]);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $passkey->authenticator_icon_light);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $passkey->authenticator_icon_dark);
        $this->assertArrayNotHasKey('authenticator_icon_light', $passkey->toArray());
    }

    public function testItIncludesAuthenticatorIconsInSerializationWhenExplicitlyAppended(): void
    {
        $passkey = new Passkey([
            'name' => 'Test',
            'credential_id' => 'cred',
            'credential' => ['aaguid' => 'a11a5faa-9f32-4b8c-8c5d-2f7d13e8c942'],
        ]);

        $array = $passkey->append(['authenticator_icon_light', 'authenticator_icon_dark'])->toArray();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $array['authenticator_icon_light']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $array['authenticator_icon_dark']);
    }
}

class UserWithCustomPrimaryKey extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;

    protected ?string $table = 'users_with_custom_primary_keys';

    protected ?string $primaryKey = 'user_id';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $guarded = [];
}
