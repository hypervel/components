<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Fortify\Events\RecoveryCodeReplaced;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Fortify\Fixtures\UserWithTwoFactor;
use UnexpectedValueException;

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

    public function testConsumeRecoveryCodeOnlyConsumesAValidCodeOnce(): void
    {
        Event::fake();

        $codes = ['abc123', 'def456'];

        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
        ]);

        $this->assertTrue($user->consumeRecoveryCode('abc123'));
        $this->assertFalse($user->fresh()->consumeRecoveryCode('abc123'));

        $freshCodes = $user->fresh()->recoveryCodes();

        $this->assertCount(2, $freshCodes);
        $this->assertNotContains('abc123', $freshCodes);
        $this->assertContains('def456', $freshCodes);
        Event::assertDispatchedTimes(RecoveryCodeReplaced::class, 1);
    }

    public function testRecoveryCodesReturnEmptyArrayWhenStateIsAbsent(): void
    {
        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_recovery_codes' => null,
        ]);

        $this->assertSame([], $user->fresh()->recoveryCodes());

        $user->forceFill(['two_factor_recovery_codes' => ''])->save();

        $this->assertSame([], $user->fresh()->recoveryCodes());
    }

    public function testRecoveryCodesReturnEncryptedEmptyArray(): void
    {
        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode([], JSON_THROW_ON_ERROR),
            ),
        ]);

        $this->assertSame([], $user->fresh()->recoveryCodes());
    }

    public function testRecoveryCodesRejectMalformedCiphertext(): void
    {
        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_recovery_codes' => 'invalid-ciphertext',
        ]);

        $this->expectException(DecryptException::class);

        $user->recoveryCodes();
    }

    public function testRecoveryCodesRejectDecodedNonArrayJson(): void
    {
        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode('not-an-array', JSON_THROW_ON_ERROR),
            ),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Two-factor recovery codes must decode to an array.');

        $user->recoveryCodes();
    }

    public function testPersistedUserMissingTwoFactorSecretCannotReportAuthenticationState(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $user = UserWithTwoFactor::forceCreate(['email' => 'taylor@example.test']);
        $partialUser = UserWithTwoFactor::query()->select('id')->findOrFail($user->getKey());

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage('two_factor_secret');

        $partialUser->hasEnabledTwoFactorAuthentication();
    }

    public function testConfirmedTwoFactorAuthenticationRequiresLoadedConfirmationState(): void
    {
        Model::preventAccessingMissingAttributes(false);
        config()->set('fortify.features', [Features::twoFactorAuthentication(['confirm' => true])]);

        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_secret' => 'secret',
        ]);
        $partialUser = UserWithTwoFactor::query()
            ->select(['id', 'two_factor_secret'])
            ->findOrFail($user->getKey());

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage('two_factor_confirmed_at');

        $partialUser->hasEnabledTwoFactorAuthentication();
    }

    public function testUnconfirmedTwoFactorAuthenticationDoesNotRequireConfirmationState(): void
    {
        Model::preventAccessingMissingAttributes(false);
        config()->set('fortify.features', [Features::twoFactorAuthentication()]);

        $user = UserWithTwoFactor::forceCreate([
            'email' => 'taylor@example.test',
            'two_factor_secret' => 'secret',
        ]);
        $partialUser = UserWithTwoFactor::query()
            ->select(['id', 'two_factor_secret'])
            ->findOrFail($user->getKey());

        $this->assertTrue($partialUser->hasEnabledTwoFactorAuthentication());
    }

    public function testFreshAndJustCreatedUsersRetainNullableTwoFactorDefaults(): void
    {
        Model::preventAccessingMissingAttributes(false);
        config()->set('fortify.features', [Features::twoFactorAuthentication(['confirm' => true])]);

        $this->assertFalse((new UserWithTwoFactor)->hasEnabledTwoFactorAuthentication());

        $user = UserWithTwoFactor::forceCreate(['email' => 'taylor@example.test']);

        $this->assertTrue($user->wasRecentlyCreated);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }
}
