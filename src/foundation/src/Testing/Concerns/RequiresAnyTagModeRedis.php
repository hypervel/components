<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

/**
 * Skips tests when the environment doesn't support Redis any-mode tag
 * operations.
 *
 * Any-mode requires:
 * - phpredis >= 6.3.0 (HSETEX command)
 * - Redis >= 8.0.0 OR Valkey >= 9.0.0 (HEXPIRE command)
 *
 * Expects InteractsWithRedis on the same class — uses redisClient() to
 * probe the server version. The support check is memoized per process.
 */
trait RequiresAnyTagModeRedis
{
    /**
     * Minimum phpredis version required for any-mode (HSETEX support).
     */
    private const string PHPREDIS_MIN_VERSION = '6.3.0';

    /**
     * Minimum Redis version required for any-mode (HEXPIRE support).
     */
    private const string REDIS_MIN_VERSION = '8.0.0';

    /**
     * Minimum Valkey version required for any-mode (HEXPIRE support).
     */
    private const string VALKEY_MIN_VERSION = '9.0.0';

    /**
     * Cached result of the any-mode support check (null = not checked yet).
     */
    private static ?bool $anyTagModeSupported = null;

    /**
     * Cached skip reason when any-mode is not supported.
     */
    private static string $anyTagModeSkipReason = '';

    /**
     * Skip the current test if any-mode tag requirements are not met.
     *
     * The check runs once per process and is then cached for all
     * subsequent calls.
     */
    protected function skipIfAnyTagModeUnsupported(): void
    {
        if (self::$anyTagModeSupported === null) {
            self::$anyTagModeSupported = $this->checkAnyTagModeSupport();
        }

        if (! self::$anyTagModeSupported) {
            $this->markTestSkipped(self::$anyTagModeSkipReason);
        }
    }

    /**
     * Check whether the environment supports any-mode tag operations.
     */
    private function checkAnyTagModeSupport(): bool
    {
        $phpredisVersion = $this->detectedPhpredisVersion();

        if (version_compare($phpredisVersion, self::PHPREDIS_MIN_VERSION, '<')) {
            self::$anyTagModeSkipReason = 'Any tag mode requires phpredis >= '
                . self::PHPREDIS_MIN_VERSION . " (installed: {$phpredisVersion})";

            return false;
        }

        $info = $this->detectedServerInfo();

        if (isset($info['valkey_version'])) {
            if (version_compare($info['valkey_version'], self::VALKEY_MIN_VERSION, '<')) {
                self::$anyTagModeSkipReason = 'Any tag mode requires Valkey >= '
                    . self::VALKEY_MIN_VERSION . " (installed: {$info['valkey_version']})";

                return false;
            }
        } elseif (isset($info['redis_version'])) {
            if (version_compare($info['redis_version'], self::REDIS_MIN_VERSION, '<')) {
                self::$anyTagModeSkipReason = 'Any tag mode requires Redis >= '
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
}
