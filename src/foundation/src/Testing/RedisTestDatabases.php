<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use RuntimeException;

class RedisTestDatabases
{
    /**
     * Get the base Redis DB number.
     */
    public static function baseDatabase(): int
    {
        return static::integerEnvironment('REDIS_DB', 0);
    }

    /**
     * Get the first Redis DB number available for parallel test workers.
     */
    public static function minimumDatabase(): int
    {
        return static::integerEnvironment('REDIS_TEST_DB_MIN', static::baseDatabase());
    }

    /**
     * Get the last Redis DB number available for parallel test workers.
     */
    public static function maximumDatabase(): int
    {
        return static::integerEnvironment('REDIS_TEST_DB_MAX', 15);
    }

    /**
     * Get the configured secondary Redis DB number.
     */
    public static function configuredSecondaryDatabase(): ?int
    {
        $value = env('REDIS_TEST_SECONDARY_DB');

        return $value === null
            ? null
            : static::integerEnvironmentValue('REDIS_TEST_SECONDARY_DB', $value);
    }

    /**
     * Get the primary Redis DB number for a test worker.
     */
    public static function primaryDatabase(string|false $token): int
    {
        if ($token === false) {
            return static::baseDatabase();
        }

        return static::databaseForToken($token);
    }

    /**
     * Get the primary Redis DB number for a parallel testing token.
     */
    public static function databaseForToken(string $token): int
    {
        $workerIndex = static::workerIndex($token);
        $databases = static::workerDatabases();

        if (! array_key_exists($workerIndex, $databases)) {
            throw new RuntimeException(sprintf(
                'Parallel Redis worker [%s] has no configured Redis database. '
                . 'Reduce the ParaTest process count or adjust REDIS_TEST_DB_MIN, REDIS_TEST_DB_MAX, and REDIS_TEST_SECONDARY_DB.',
                $token,
            ));
        }

        return $databases[$workerIndex];
    }

    /**
     * Get the secondary Redis DB number.
     */
    public static function secondaryDatabase(string|false $token): int
    {
        $database = static::configuredSecondaryDatabase();

        if ($database === null) {
            throw new RuntimeException('REDIS_TEST_SECONDARY_DB must be set before requesting the secondary Redis test database.');
        }

        if ($database === static::primaryDatabase($token)) {
            throw new RuntimeException('REDIS_TEST_SECONDARY_DB must be different from the current Redis test database.');
        }

        return $database;
    }

    /**
     * Get the configured Redis worker databases.
     *
     * @return array<int, int>
     */
    public static function workerDatabases(): array
    {
        $minimumDatabase = static::minimumDatabase();
        $maximumDatabase = static::maximumDatabase();

        if ($maximumDatabase < $minimumDatabase) {
            throw new RuntimeException('REDIS_TEST_DB_MAX must be greater than or equal to REDIS_TEST_DB_MIN.');
        }

        $secondaryDatabase = static::configuredSecondaryDatabase();
        $databases = range($minimumDatabase, $maximumDatabase);

        if ($secondaryDatabase !== null) {
            $databases = array_values(array_filter(
                $databases,
                static fn (int $database): bool => $database !== $secondaryDatabase,
            ));
        }

        return $databases;
    }

    /**
     * Get the zero-based Redis worker index for a ParaTest token.
     */
    public static function workerIndex(string $token): int
    {
        if (! ctype_digit($token) || (int) $token < 1) {
            throw new RuntimeException('TEST_TOKEN must be a positive integer for Redis parallel testing.');
        }

        return (int) $token - 1;
    }

    /**
     * Get a non-negative integer Redis environment value.
     */
    public static function integerEnvironment(string $key, int $default): int
    {
        $value = env($key);

        if ($value === null) {
            return $default;
        }

        return static::integerEnvironmentValue($key, $value);
    }

    /**
     * Parse a non-negative integer Redis environment value.
     */
    public static function integerEnvironmentValue(string $key, mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new RuntimeException("{$key} must be a non-negative integer.");
            }

            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("{$key} must be a non-negative integer.");
    }
}
