<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

/**
 * Skips tests when the environment doesn't support Redis hash field
 * expiration.
 *
 * Hash field expiration requires:
 * - phpredis >= 6.3.0 (HSETEX command)
 * - Redis >= 8.0.0 or Valkey >= 9.0.0 (HEXPIRE command)
 *
 * Expects InteractsWithRedis on the same class — uses redisClient() to
 * probe the server version. The support check is memoized per process.
 */
trait RequiresHashFieldExpiration
{
    /**
     * Minimum phpredis version required for hash field expiration.
     */
    private const string PHPREDIS_MIN_VERSION = '6.3.0';

    /**
     * Minimum Redis version required for hash field expiration.
     */
    private const string REDIS_MIN_VERSION = '8.0.0';

    /**
     * Minimum Valkey version required for hash field expiration.
     */
    private const string VALKEY_MIN_VERSION = '9.0.0';

    /**
     * Cached result of the hash field expiration support check.
     */
    private static ?bool $hashFieldExpirationSupported = null;

    /**
     * Cached skip reason when hash field expiration is not supported.
     */
    private static string $hashFieldExpirationSkipReason = '';

    /**
     * Skip the current test if hash field expiration requirements are not met.
     *
     * The check runs once per process and is then cached for all
     * subsequent calls.
     */
    protected function skipIfHashFieldExpirationUnsupported(): void
    {
        if (self::$hashFieldExpirationSupported === null) {
            self::$hashFieldExpirationSupported = $this->checkHashFieldExpirationSupport();
        }

        if (! self::$hashFieldExpirationSupported) {
            $this->markTestSkipped(self::$hashFieldExpirationSkipReason);
        }
    }

    /**
     * Check whether the environment supports hash field expiration.
     */
    private function checkHashFieldExpirationSupport(): bool
    {
        $phpredisVersion = $this->detectedPhpredisVersion();

        if (version_compare($phpredisVersion, self::PHPREDIS_MIN_VERSION, '<')) {
            self::$hashFieldExpirationSkipReason = 'Hash field expiration requires phpredis >= '
                . self::PHPREDIS_MIN_VERSION . " (installed: {$phpredisVersion})";

            return false;
        }

        $info = $this->detectedServerInfo();

        if (isset($info['valkey_version'])) {
            if (version_compare($info['valkey_version'], self::VALKEY_MIN_VERSION, '<')) {
                self::$hashFieldExpirationSkipReason = 'Hash field expiration requires Valkey >= '
                    . self::VALKEY_MIN_VERSION . " (installed: {$info['valkey_version']})";

                return false;
            }
        } elseif (isset($info['redis_version'])) {
            if (version_compare($info['redis_version'], self::REDIS_MIN_VERSION, '<')) {
                self::$hashFieldExpirationSkipReason = 'Hash field expiration requires Redis >= '
                    . self::REDIS_MIN_VERSION . " (installed: {$info['redis_version']})";

                return false;
            }
        }

        return true;
    }

    /**
     * Detect the installed phpredis version. Overridable for tests.
     */
    protected function detectedPhpredisVersion(): string
    {
        return phpversion('redis') ?: '0';
    }

    /**
     * Detect the Redis/Valkey server info. Overridable for tests.
     *
     * @return array<string, mixed>
     */
    protected function detectedServerInfo(): array
    {
        return $this->redisClient()->info('server');
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$hashFieldExpirationSupported = null;
        self::$hashFieldExpirationSkipReason = '';
    }
}
