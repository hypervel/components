<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use SensitiveParameter;

interface TokenRepositoryInterface
{
    /**
     * Create a new token.
     */
    public function create(CanResetPasswordContract $user): string;

    /**
     * Determine if a token record exists and is valid.
     */
    public function exists(CanResetPasswordContract $user, #[SensitiveParameter] string $token): bool;

    /**
     * Determine if the given user recently created a password reset token.
     */
    public function recentlyCreatedToken(CanResetPasswordContract $user): bool;

    /**
     * Delete a token record.
     */
    public function delete(CanResetPasswordContract $user): void;

    /**
     * Delete expired tokens.
     */
    public function deleteExpired(): void;
}
