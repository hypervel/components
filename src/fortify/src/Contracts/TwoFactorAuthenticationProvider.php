<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Contracts;

interface TwoFactorAuthenticationProvider
{
    /**
     * Generate a new secret key.
     */
    public function generateSecretKey(int $secretLength = 32): string;

    /**
     * Get the two factor authentication QR code URL.
     */
    public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string;

    /**
     * Verify the given token.
     */
    public function verify(string $secret, string $code): bool;
}
