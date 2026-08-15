<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Notifications\ResetPassword;
use Hypervel\Auth\Passwords\PasswordBroker;
use Hypervel\Context\CoroutineContext;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

#[WithConfig('auth.passwords.admins', [
    'driver' => 'database',
    'provider' => 'users',
    'table' => 'admin_password_reset_tokens',
    'connection' => null,
    'expire' => 15,
    'throttle' => 60,
])]
class ResetPasswordNotificationTest extends TestCase
{
    public function testExpiryIsCapturedFromSendingBrokerContext(): void
    {
        CoroutineContext::set(PasswordBroker::SENDING_BROKER_CONTEXT_KEY, 'admins');

        $notification = new ResetPassword('token');

        $this->assertSame(15, $notification->expireMinutes);
        $this->assertContains(
            'This password reset link will expire in 15 minutes.',
            $this->mailLines($notification),
        );
    }

    public function testExpiryFallsBackToDefaultBrokerOutsideSendFlow(): void
    {
        CoroutineContext::forget(PasswordBroker::SENDING_BROKER_CONTEXT_KEY);

        $notification = new ResetPassword('token');

        $this->assertSame(60, $notification->expireMinutes);
        $this->assertContains(
            'This password reset link will expire in 60 minutes.',
            $this->mailLines($notification),
        );
    }

    public function testExpirySurvivesSerialization(): void
    {
        CoroutineContext::set(PasswordBroker::SENDING_BROKER_CONTEXT_KEY, 'admins');

        $notification = unserialize(serialize(new ResetPassword('token')));

        CoroutineContext::forget(PasswordBroker::SENDING_BROKER_CONTEXT_KEY);

        $this->assertInstanceOf(ResetPassword::class, $notification);
        $this->assertSame(15, $notification->expireMinutes);
        $this->assertContains(
            'This password reset link will expire in 15 minutes.',
            $this->mailLines($notification),
        );
    }

    public function testExpiryFailsWhenBrokerSettingIsOmitted(): void
    {
        config()->set('auth.passwords.admins', [
            'driver' => 'database',
            'provider' => 'users',
            'table' => 'admin_password_reset_tokens',
            'connection' => null,
            'throttle' => 60,
        ]);
        CoroutineContext::set(PasswordBroker::SENDING_BROKER_CONTEXT_KEY, 'admins');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configuration value for key [auth.passwords.admins.expire] must be an integer, NULL given.'
        );

        new ResetPassword('token');
    }

    /**
     * Get the notification mail lines.
     */
    private function mailLines(ResetPassword $notification): array
    {
        ResetPassword::createUrlUsing(fn (): string => 'https://example.test/reset');

        $mail = $notification->toMail(new class {
            /**
             * Get the e-mail address where password reset links are sent.
             */
            public function getEmailForPasswordReset(): string
            {
                return 'user@example.test';
            }
        });

        return [...$mail->introLines, ...$mail->outroLines];
    }
}
