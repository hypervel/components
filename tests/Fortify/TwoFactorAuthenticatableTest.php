<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Fortify\Fixtures\UserWithTwoFactor;

class TwoFactorAuthenticatableTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Create fixture tables after refreshing the database.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function testReplaceRecoveryCodeOnlyReplacesExactDecodedEntry(): void
    {
        $codes = ['abc123', 'prefix-abc123-suffix'];

        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
        ]);

        $user->replaceRecoveryCode('abc123');

        $freshCodes = $user->fresh()->recoveryCodes();

        $this->assertCount(2, $freshCodes);
        $this->assertNotContains('abc123', $freshCodes);
        $this->assertContains('prefix-abc123-suffix', $freshCodes);
    }
}
