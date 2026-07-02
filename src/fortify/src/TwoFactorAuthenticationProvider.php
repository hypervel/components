<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticationProvider implements TwoFactorAuthenticationProviderContract
{
    /**
     * Create a new two factor authentication provider instance.
     */
    public function __construct(
        private readonly Google2FA $engine,
        private readonly ?Repository $cache = null,
    ) {
    }

    /**
     * Generate a new secret key.
     */
    public function generateSecretKey(int $secretLength = 32): string
    {
        return $this->engine->generateSecretKey($secretLength);
    }

    /**
     * Get the two factor authentication QR code URL.
     */
    public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string
    {
        return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
    }

    /**
     * Verify the given code.
     */
    public function verify(string $secret, string $code): bool
    {
        $window = Features::option(Features::twoFactorAuthentication(), 'window');
        $window = is_int($window) ? $window : null;
        $key = 'fortify.2fa_codes.' . hash('xxh128', $secret . '|' . $code);

        $timestamp = $this->engine->verifyKeyNewer(
            $secret,
            $code,
            $this->cache?->get($key),
            $window,
        );

        if ($timestamp === false) {
            return false;
        }

        if ($timestamp === true) {
            $timestamp = $this->engine->getTimestamp();
        }

        $this->cache?->put($key, $timestamp, ($this->engine->getWindow($window) ?: 1) * 60);

        return true;
    }
}
