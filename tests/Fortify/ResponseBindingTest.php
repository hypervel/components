<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Contracts;
use Hypervel\Fortify\FortifyServiceProvider;
use Hypervel\Fortify\Http\Responses;
use PHPUnit\Framework\Attributes\DataProvider;

class ResponseBindingTest extends TestCase
{
    #[DataProvider('responseBindingsProvider')]
    public function testResponseClassImplementsTheContractItIsBoundTo(string $contract, string $response): void
    {
        $this->assertTrue(
            is_a($response, $contract, true),
            "The [{$response}] class should implement the [{$contract}] contract."
        );

        // Password reset responses receive the broker status from their controller.
        $this->assertInstanceOf(
            $response,
            $this->app->make($contract, ['status' => 'passwords.sent']),
            "The [{$contract}] contract should resolve to [{$response}]."
        );
    }

    /**
     * The response bindings registered by the Fortify service provider.
     *
     * Mirrors {@see FortifyServiceProvider::registerResponseBindings()}.
     *
     * @return array<string, array{class-string, class-string}>
     */
    public static function responseBindingsProvider(): array
    {
        return [
            'EmailVerificationNotificationSentResponse' => [Contracts\EmailVerificationNotificationSentResponse::class, Responses\EmailVerificationNotificationSentResponse::class],
            'FailedPasswordConfirmationResponse' => [Contracts\FailedPasswordConfirmationResponse::class, Responses\FailedPasswordConfirmationResponse::class],
            'FailedPasswordResetLinkRequestResponse' => [Contracts\FailedPasswordResetLinkRequestResponse::class, Responses\FailedPasswordResetLinkRequestResponse::class],
            'FailedPasswordResetResponse' => [Contracts\FailedPasswordResetResponse::class, Responses\FailedPasswordResetResponse::class],
            'FailedTwoFactorLoginResponse' => [Contracts\FailedTwoFactorLoginResponse::class, Responses\FailedTwoFactorLoginResponse::class],
            'LockoutResponse' => [Contracts\LockoutResponse::class, Responses\LockoutResponse::class],
            'LoginResponse' => [Contracts\LoginResponse::class, Responses\LoginResponse::class],
            'LogoutResponse' => [Contracts\LogoutResponse::class, Responses\LogoutResponse::class],
            'PasswordConfirmedResponse' => [Contracts\PasswordConfirmedResponse::class, Responses\PasswordConfirmedResponse::class],
            'PasswordResetResponse' => [Contracts\PasswordResetResponse::class, Responses\PasswordResetResponse::class],
            'PasswordUpdateResponse' => [Contracts\PasswordUpdateResponse::class, Responses\PasswordUpdateResponse::class],
            'ProfileInformationUpdatedResponse' => [Contracts\ProfileInformationUpdatedResponse::class, Responses\ProfileInformationUpdatedResponse::class],
            'RecoveryCodesGeneratedResponse' => [Contracts\RecoveryCodesGeneratedResponse::class, Responses\RecoveryCodesGeneratedResponse::class],
            'RegisterResponse' => [Contracts\RegisterResponse::class, Responses\RegisterResponse::class],
            'SuccessfulPasswordResetLinkRequestResponse' => [Contracts\SuccessfulPasswordResetLinkRequestResponse::class, Responses\SuccessfulPasswordResetLinkRequestResponse::class],
            'TwoFactorConfirmedResponse' => [Contracts\TwoFactorConfirmedResponse::class, Responses\TwoFactorConfirmedResponse::class],
            'TwoFactorDisabledResponse' => [Contracts\TwoFactorDisabledResponse::class, Responses\TwoFactorDisabledResponse::class],
            'TwoFactorEnabledResponse' => [Contracts\TwoFactorEnabledResponse::class, Responses\TwoFactorEnabledResponse::class],
            'TwoFactorLoginResponse' => [Contracts\TwoFactorLoginResponse::class, Responses\TwoFactorLoginResponse::class],
            'VerifyEmailResponse' => [Contracts\VerifyEmailResponse::class, Responses\VerifyEmailResponse::class],
        ];
    }
}
