<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Dotenv\Dotenv;

class DotenvManager
{
    /**
     * The keys and values loaded from the last load/reload call.
     *
     * @var null|array<string, string>
     */
    protected static ?array $cachedValues = null;

    /**
     * Load environment variables from the given paths.
     *
     * This is a one-shot method — subsequent calls return early if values
     * have already been loaded. Use reload() to re-read the env file.
     */
    public static function load(array $paths): void
    {
        if (static::$cachedValues !== null) {
            return;
        }

        static::$cachedValues = static::createDotenv($paths)->load();
    }

    /**
     * Reload environment variables from the given paths.
     *
     * Deletes previously loaded env vars from putenv, resets the Env
     * repository's ImmutableWriter so it treats all keys as writable,
     * then re-reads the env file.
     */
    public static function reload(array $paths): void
    {
        if (static::$cachedValues === null) {
            static::load($paths);

            return;
        }

        Env::deleteMany(array_keys(static::$cachedValues));
        Env::resetRepository();

        static::$cachedValues = static::createDotenv($paths)->load();
    }

    /**
     * Reset all static state, allowing load() to run again.
     *
     * Removes any previously loaded env vars before clearing
     * the internal tracking, so immutable repositories don't see stale values.
     */
    public static function reset(): void
    {
        if (static::$cachedValues !== null) {
            Env::deleteMany(array_keys(static::$cachedValues));
        }

        Env::resetRepository();

        static::$cachedValues = null;
    }

    /**
     * Create a Dotenv instance using Env's repository.
     */
    protected static function createDotenv(array $paths): Dotenv
    {
        return Dotenv::create(Env::getRepository(), $paths);
    }
}
