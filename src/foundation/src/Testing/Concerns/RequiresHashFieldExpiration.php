<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Redis\RedisRequirements;

/**
 * Skips tests when the environment doesn't support Redis hash field
 * expiration.
 *
 * Expects InteractsWithRedis on the same class — uses redisClient() to
 * probe the server version. The support check is memoized per process.
 */
trait RequiresHashFieldExpiration
{
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
        $error = RedisRequirements::hashFieldExpirationClientError($this->detectedPhpredisVersion())
            ?? RedisRequirements::hashFieldExpirationServerError($this->detectedServerInfo());

        self::$hashFieldExpirationSkipReason = $error ?? '';

        return $error === null;
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
     * Flush the memoized hash field expiration support check.
     *
     * Tests only. Resets the capability result shared by tests in this class.
     */
    public static function flushHashFieldExpirationState(): void
    {
        self::$hashFieldExpirationSupported = null;
        self::$hashFieldExpirationSkipReason = '';
    }
}
