<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use SensitiveParameter;

trait CanResetPassword
{
    /**
     * Get the e-mail address where password reset links are sent.
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->email;
    }

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] string $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
