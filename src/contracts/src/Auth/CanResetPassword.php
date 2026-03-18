<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface CanResetPassword
{
    /**
     * Get the e-mail address where password reset links are sent.
     */
    public function getEmailForPasswordReset(): string;

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification(string $token): void;
}
