<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

/**
 * Helper class for parallel testing support.
 *
 * Provides access to the TEST_TOKEN environment variable set by Paratest
 * when running tests with --parallel, and utilities for generating
 * parallel-safe resource identifiers.
 */
final class ParallelTesting
{
    /**
     * Get the current parallel test token.
     *
     * Returns the TEST_TOKEN set by Paratest, or null if not running in parallel.
     * Token is typically an integer (1, 2, 3, ...) identifying the worker process.
     */
    public static function token(): ?int
    {
        $token = getenv('TEST_TOKEN');

        if ($token === false || $token === '') {
            return null;
        }

        return (int) $token;
    }

    /**
     * Check if tests are running in parallel mode.
     */
    public static function inParallel(): bool
    {
        return self::token() !== null;
    }

    /**
     * Get a parallel-safe database name.
     *
     * Returns "{base}_{token}" when running in parallel, or just "{base}" otherwise.
     */
    public static function databaseName(string $baseDatabase): string
    {
        $token = self::token();

        if ($token === null) {
            return $baseDatabase;
        }

        return "{$baseDatabase}_{$token}";
    }

    /**
     * Get a parallel-safe Redis key prefix.
     *
     * Returns "{basePrefix}{token}:" when running in parallel, or just "{basePrefix}" otherwise.
     */
    public static function redisPrefix(string $basePrefix = 'test:'): string
    {
        $token = self::token();

        if ($token === null) {
            return $basePrefix;
        }

        $basePrefix = rtrim($basePrefix, ':');

        return "{$basePrefix}_{$token}:";
    }
}
