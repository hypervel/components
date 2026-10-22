<?php

declare(strict_types=1);

namespace Hypervel\Redis;

class RedisRequirements
{
    /**
     * Baseline for ordinary connections; individual features may require newer clients.
     */
    public const string MINIMUM_PHPREDIS_VERSION = '6.1.0';

    /**
     * Minimum phpredis version required for hash field expiration.
     */
    public const string HASH_FIELD_EXPIRATION_PHPREDIS_VERSION = '6.3.0';

    /**
     * Minimum Redis version required for hash field expiration.
     */
    public const string HASH_FIELD_EXPIRATION_REDIS_VERSION = '8.0.0';

    /**
     * Minimum Valkey version required for hash field expiration.
     */
    public const string HASH_FIELD_EXPIRATION_VALKEY_VERSION = '9.0.0';

    /**
     * Get the reason a client cannot support hash field expiration.
     */
    public static function hashFieldExpirationClientError(string $version): ?string
    {
        if (version_compare($version, self::HASH_FIELD_EXPIRATION_PHPREDIS_VERSION, '<')) {
            return 'Hash field expiration requires phpredis >= '
                . self::HASH_FIELD_EXPIRATION_PHPREDIS_VERSION . " (installed: {$version})";
        }

        return null;
    }

    /**
     * Get the reason a server cannot support hash field expiration.
     *
     * @param array<string, mixed> $info
     */
    public static function hashFieldExpirationServerError(array $info): ?string
    {
        if (isset($info['valkey_version'])) {
            if (version_compare($info['valkey_version'], self::HASH_FIELD_EXPIRATION_VALKEY_VERSION, '<')) {
                return 'Hash field expiration requires Valkey >= '
                    . self::HASH_FIELD_EXPIRATION_VALKEY_VERSION . " (installed: {$info['valkey_version']})";
            }
        } elseif (isset($info['redis_version'])) {
            if (version_compare($info['redis_version'], self::HASH_FIELD_EXPIRATION_REDIS_VERSION, '<')) {
                return 'Hash field expiration requires Redis >= '
                    . self::HASH_FIELD_EXPIRATION_REDIS_VERSION . " (installed: {$info['redis_version']})";
            }
        } else {
            return 'Could not determine Redis/Valkey version for hash field expiration.';
        }

        return null;
    }
}
