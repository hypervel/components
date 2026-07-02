<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Contracts;

interface TwoFactorAuthenticationUser
{
    /**
     * Determine if two-factor authentication has been enabled.
     */
    public function hasEnabledTwoFactorAuthentication(): bool;

    /**
     * Get the two factor authentication recovery codes.
     *
     * @return array<int, string>
     */
    public function recoveryCodes(): array;

    /**
     * Consume the given recovery code if it is still valid.
     */
    public function consumeRecoveryCode(string $code): bool;

    /**
     * Replace the given recovery code with a new one.
     */
    public function replaceRecoveryCode(string $code): void;

    /**
     * Get the SVG element for the user's two factor authentication QR code.
     */
    public function twoFactorQrCodeSvg(): string;

    /**
     * Get the two factor authentication QR code URL.
     */
    public function twoFactorQrCodeUrl(): string;
}
