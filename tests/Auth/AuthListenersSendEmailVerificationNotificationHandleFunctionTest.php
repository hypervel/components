<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Events\Registered;
use Hypervel\Auth\GenericUser;
use Hypervel\Auth\Listeners\SendEmailVerificationNotification;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Tests\TestCase;

class AuthListenersSendEmailVerificationNotificationHandleFunctionTest extends TestCase
{
    public function testWillExecuted(): void
    {
        $user = $this->unverifiedUser();

        $listener = new SendEmailVerificationNotification;

        $listener->handle(new Registered($user));

        $this->assertSame(1, $user->notificationsSent);
    }

    public function testUserIsNotInstanceOfMustVerifyEmail(): void
    {
        // Has the verification methods but doesn't implement MustVerifyEmail.
        $user = new class([]) extends GenericUser {
            public int $notificationsSent = 0;

            /**
             * Determine if the user has verified their email address.
             */
            public function hasVerifiedEmail(): bool
            {
                return false;
            }

            /**
             * Send the email verification notification.
             */
            public function sendEmailVerificationNotification(): void
            {
                ++$this->notificationsSent;
            }
        };

        $listener = new SendEmailVerificationNotification;

        $listener->handle(new Registered($user));

        $this->assertSame(0, $user->notificationsSent);
    }

    public function testHasVerifiedEmailAsTrue(): void
    {
        $user = $this->unverifiedUser();
        $user->verified = true;

        $listener = new SendEmailVerificationNotification;

        $listener->handle(new Registered($user));

        $this->assertSame(0, $user->notificationsSent);
    }

    /**
     * Create an unverified user that counts verification notifications.
     */
    protected function unverifiedUser(): GenericUser&MustVerifyEmail
    {
        return new class([]) extends GenericUser implements MustVerifyEmail {
            public bool $verified = false;

            public int $notificationsSent = 0;

            /**
             * Determine if the user has verified their email address.
             */
            public function hasVerifiedEmail(): bool
            {
                return $this->verified;
            }

            /**
             * Mark the given user's email as verified.
             */
            public function markEmailAsVerified(): bool
            {
                $this->verified = true;

                return true;
            }

            /**
             * Mark the given user's email as unverified.
             */
            public function markEmailAsUnverified(): bool
            {
                $this->verified = false;

                return true;
            }

            /**
             * Send the email verification notification.
             */
            public function sendEmailVerificationNotification(): void
            {
                ++$this->notificationsSent;
            }

            /**
             * Get the email address that should be used for verification.
             */
            public function getEmailForVerification(): string
            {
                return 'test@example.com';
            }
        };
    }
}
