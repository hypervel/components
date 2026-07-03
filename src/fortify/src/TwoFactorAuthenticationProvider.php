<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use InvalidArgumentException;
use OTPHP\TOTP;
use OTPHP\TOTPInterface;
use Psr\Clock\ClockInterface;

class TwoFactorAuthenticationProvider implements TwoFactorAuthenticationProviderContract
{
    private const string ALGORITHM = 'SHA1';

    private const int DEFAULT_WINDOW = 1;

    private const int DIGITS = 6;

    private const int PERIOD = TOTPInterface::DEFAULT_PERIOD;

    /**
     * Create a new two factor authentication provider instance.
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?Repository $cache = null,
    ) {
    }

    /**
     * Generate a new secret key.
     */
    public function generateSecretKey(int $secretLength = 32): string
    {
        if ($secretLength < 1) {
            throw new InvalidArgumentException('Two-factor authentication secret length must be greater than zero.');
        }

        $byteLength = (int) ceil($secretLength * 5 / 8);

        return substr(TOTP::generate($this->clock, $byteLength)->getSecret(), 0, $secretLength);
    }

    /**
     * Get the two factor authentication QR code URL.
     */
    public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string
    {
        return 'otpauth://totp/'
            . rawurlencode($companyName)
            . ':'
            . rawurlencode($companyEmail)
            . '?secret='
            . $secret
            . '&issuer='
            . rawurlencode($companyName)
            . '&algorithm='
            . self::ALGORITHM
            . '&digits='
            . self::DIGITS
            . '&period='
            . self::PERIOD;
    }

    /**
     * Verify the given code.
     */
    public function verify(string $secret, string $code): bool
    {
        $window = $this->window();
        $totp = TOTP::createFromSecret($secret, $this->clock);
        $key = $this->replayCacheKey($secret, $code);
        $lastAcceptedTimecode = $this->cache?->get($key);

        $matchedTimecode = $this->matchingTimecode($totp, $code, $window);

        if ($matchedTimecode === null) {
            return false;
        }

        if (is_int($lastAcceptedTimecode) && $matchedTimecode <= $lastAcceptedTimecode) {
            return false;
        }

        $this->cache?->put($key, $matchedTimecode, $this->replayTtl($window));

        return true;
    }

    /**
     * Find the matching TOTP timecode.
     */
    private function matchingTimecode(TOTP $totp, string $code, int $window): ?int
    {
        $currentTimecode = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
        $firstTimecode = max(0, $currentTimecode - $window);
        $lastTimecode = $currentTimecode + $window;

        for ($timecode = $firstTimecode; $timecode <= $lastTimecode; ++$timecode) {
            if (hash_equals($totp->at($timecode * self::PERIOD), $code)) {
                return $timecode;
            }
        }

        return null;
    }

    /**
     * Get the configured verification window.
     */
    private function window(): int
    {
        $window = Features::option(Features::twoFactorAuthentication(), 'window');
        $window = is_int($window) ? $window : self::DEFAULT_WINDOW;

        if ($window < 0) {
            throw new InvalidArgumentException('Two-factor authentication window must be greater than or equal to zero.');
        }

        return $window;
    }

    /**
     * Get the replay cache key.
     */
    private function replayCacheKey(string $secret, string $code): string
    {
        return 'fortify.2fa_codes.' . hash('xxh128', $secret . '|' . $code);
    }

    /**
     * Get the replay cache TTL.
     */
    private function replayTtl(int $window): int
    {
        return (2 * $window + 1) * self::PERIOD;
    }
}
