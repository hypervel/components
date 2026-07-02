<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Events\RecoveryCodesGenerated;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithMigration;

#[WithMigration]
class RecoveryCodeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testNewRecoveryCodesCanBeGenerated(): void
    {
        Event::fake();

        $user = TestTwoFactorRecoveryCodeUser::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/two-factor-recovery-codes'
        );

        $response->assertStatus(200);

        Event::assertDispatched(RecoveryCodesGenerated::class);

        $user = $user->fresh();

        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertIsArray(json_decode(decrypt($user->two_factor_recovery_codes), true));
    }
}

class TestTwoFactorRecoveryCodeUser extends User
{
    protected ?string $table = 'users';
}
