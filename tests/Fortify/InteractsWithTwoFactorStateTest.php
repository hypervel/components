<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Features;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Fortify\Fixtures\FormRequestInteractsWithTwoFactorState;
use Hypervel\Tests\Fortify\Fixtures\UserWithTwoFactor;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithMigration]
class InteractsWithTwoFactorStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('fortify.features', [Features::twoFactorAuthentication()]);
        $this->app->make('config')->set('fortify-options.two-factor-authentication.confirm', true);
    }

    public function testValidationIsSkippedWhenConfirmFeatureIsDisabled(): void
    {
        $this->app->make('config')->set('fortify-options.two-factor-authentication.confirm', false);

        $formRequest = $this->createFormRequestWithUser();

        $formRequest->ensureStateIsValid();

        $this->assertFalse($formRequest->session()->has('two_factor_empty_at'));
        $this->assertFalse($formRequest->session()->has('two_factor_confirming_at'));
    }

    #[DataProvider('twoFactorStatesProvider')]
    public function testSetsEmptyAtSessionWhenTwoFactorIsDisabled(?string $secret, ?string $confirmedAt, bool $expectedDisabled): void
    {
        $attributes = [
            'two_factor_secret' => $secret ? encrypt($secret) : null,
            'two_factor_confirmed_at' => $confirmedAt === 'confirmed' ? now() : $confirmedAt,
        ];
        $user = $this->createUser($attributes);
        $formRequest = $this->createFormRequestWithUser($user);

        $formRequest->ensureStateIsValid();

        if (! $expectedDisabled) {
            $this->assertFalse($formRequest->session()->has('two_factor_empty_at'));

            return;
        }

        $this->assertTrue($formRequest->session()->has('two_factor_empty_at'));
        $this->assertIsInt($formRequest->session()->get('two_factor_empty_at'));
    }

    public static function twoFactorStatesProvider(): array
    {
        return [
            'disabled' => [null, null, true],
            'partial_setup' => ['secret', null, true],
            'enabled' => ['secret', 'confirmed', false],
        ];
    }

    public function testSetsEmptyAtWhenTwoFactorNotFullyEnabled(): void
    {
        $user = $this->createUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        $formRequest = $this->createFormRequestWithUser($user);

        $formRequest->ensureStateIsValid();

        $this->assertTrue($formRequest->session()->has('two_factor_empty_at'));
        $this->assertIsInt($formRequest->session()->get('two_factor_empty_at'));
    }

    public function testStateValidationRejectsAPersistedUserWithMissingTwoFactorState(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $user = $this->createUser();
        $partialUser = UserWithTwoFactor::query()->select('id')->findOrFail($user->getKey());
        $formRequest = $this->createFormRequestWithUser($partialUser);

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage('two_factor_secret');

        $formRequest->ensureStateIsValid();
    }

    public function testSetsConfirmingAtWhenUserBeginsConfirmationProcess(): void
    {
        $user = $this->createUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        $formRequest = $this->createFormRequestWithUser($user);
        $formRequest->session()->put('two_factor_empty_at', time() - 10);

        $formRequest->ensureStateIsValid();

        $this->assertTrue($formRequest->session()->has('two_factor_confirming_at'));
        $this->assertIsInt($formRequest->session()->get('two_factor_confirming_at'));
    }

    #[DataProvider('confirmationBlockersProvider')]
    public function testDoesNotSetConfirmingAtWhenConditionsNotMet(array $userAttributes, array $sessionData, string $description): void
    {
        $attributes = $userAttributes;
        if ($attributes['two_factor_secret'] === 'secret') {
            $attributes['two_factor_secret'] = encrypt('secret');
        }
        if ($attributes['two_factor_confirmed_at'] === 'confirmed') {
            $attributes['two_factor_confirmed_at'] = now();
        }
        $user = $this->createUser($attributes);
        $formRequest = $this->createFormRequestWithUser($user);

        foreach ($sessionData as $key => $value) {
            $formRequest->session()->put($key, $value);
        }

        $formRequest->ensureStateIsValid();

        $this->assertFalse($formRequest->session()->has('two_factor_confirming_at'), $description);
    }

    public static function confirmationBlockersProvider(): array
    {
        $pastTime = time() - 10;

        return [
            'no_secret' => [
                ['two_factor_secret' => null, 'two_factor_confirmed_at' => null],
                ['two_factor_empty_at' => $pastTime],
                'Should not set confirming_at without secret',
            ],
            'already_confirmed' => [
                ['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => 'confirmed'],
                ['two_factor_empty_at' => $pastTime],
                'Should not set confirming_at when already confirmed',
            ],
            'already_confirming' => [
                ['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => null],
                ['two_factor_empty_at' => $pastTime, 'two_factor_confirming_at' => time() - 5],
                'Should not overwrite existing confirming_at timestamp',
            ],
        ];
    }

    public function testDisablesTwoFactorWhenConfirmationIsAbandoned(): void
    {
        $user = $this->createUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        $formRequest = $this->createFormRequestWithUser($user);
        $formRequest->session()->put('two_factor_confirming_at', time() - 10);

        $formRequest->ensureStateIsValid();

        $this->assertNull($user->two_factor_secret);
        $this->assertTrue($formRequest->session()->has('two_factor_empty_at'));
        $this->assertFalse($formRequest->session()->has('two_factor_confirming_at'));
    }

    public function testDisabledToConfirmingToAbandonedState(): void
    {
        $user = $this->createUser([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $formRequest = $this->createFormRequestWithUser($user);

        $formRequest->ensureStateIsValid();
        $this->assertTrue($formRequest->session()->has('two_factor_empty_at'));

        $user->two_factor_secret = encrypt('secret');
        $user->save();
        $formRequest = $this->createFormRequestWithUser($user);
        $formRequest->ensureStateIsValid();
        $this->assertTrue($formRequest->session()->has('two_factor_confirming_at'));

        $formRequest->session()->put('two_factor_confirming_at', time() - 10);
        $formRequest = $this->createFormRequestWithUser($user);
        $formRequest->ensureStateIsValid();

        $this->assertNull($user->two_factor_secret);
        $this->assertTrue($formRequest->session()->has('two_factor_empty_at'));
        $this->assertFalse($formRequest->session()->has('two_factor_confirming_at'));
    }

    public function testDoesNotDisableWhenCodeInOldInputIsPresent(): void
    {
        $user = $this->createUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        $formRequest = $this->createFormRequestWithUser($user);
        $formRequest->session()->put('two_factor_confirming_at', time() - 10);
        $formRequest->session()->flashInput(['code' => '123456']);

        $formRequest->ensureStateIsValid();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertTrue($formRequest->session()->has('two_factor_empty_at'));
        $this->assertTrue($formRequest->session()->has('two_factor_confirming_at'));
    }

    public function testConfirmingAtTimestampIsCurrentTime(): void
    {
        $user = $this->createUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        $formRequest = $this->createFormRequestWithUser($user);
        $formRequest->session()->put('two_factor_empty_at', time() - 10);

        $beforeTime = time();
        $formRequest->ensureStateIsValid();
        $afterTime = time();

        $timestamp = $formRequest->session()->get('two_factor_confirming_at');
        $this->assertGreaterThanOrEqual($beforeTime, $timestamp);
        $this->assertLessThanOrEqual($afterTime, $timestamp);
    }

    private function createUser(array $attributes = []): UserWithTwoFactor
    {
        $defaults = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ];

        return UserWithTwoFactor::forceCreate(array_merge($defaults, $attributes));
    }

    private function createFormRequestWithUser(?UserWithTwoFactor $user = null): FormRequestInteractsWithTwoFactorState
    {
        $formRequest = FormRequestInteractsWithTwoFactorState::create('test');
        $formRequest->setUserResolver(fn () => $user);
        $formRequest->setHypervelSession($this->app->make('session')->driver());

        return $formRequest;
    }
}
