<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

interface MustVerifyEmail
{
    /**
     * Determine if the user has verified their email address.
     */
    public function hasVerifiedEmail(): bool;

    /**
     * Mark the given user's email as verified.
     */
    public function markEmailAsVerified(): bool;

    /**
     * Mark the given user's email as unverified.
     */
    public function markEmailAsUnverified(): bool;

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void;

    /**
     * Get the email address that should be used for verification.
     */
    public function getEmailForVerification(): string;
}
