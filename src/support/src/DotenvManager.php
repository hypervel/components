<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\AdapterInterface;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

class DotenvManager
{
    protected static ?AdapterInterface $adapter = null;

    protected static ?Dotenv $dotenv = null;

    protected static ?array $cachedValues = null;

    /**
     * Load environment variables from the given paths.
     */
    public static function load(array $paths): void
    {
        if (static::$cachedValues !== null) {
            return;
        }

        static::$cachedValues = static::getDotenv($paths)->load();
    }

    /**
     * Reload environment variables from the given paths.
     */
    public static function reload(array $paths, bool $force = false): void
    {
        if (static::$cachedValues === null) {
            static::load($paths);

            return;
        }

        foreach (static::$cachedValues as $deletedEntry => $value) {
            static::getAdapter()->delete($deletedEntry);
        }

        static::$cachedValues = static::getDotenv($paths, $force)->load();
    }

    /**
     * Reset all static state, allowing load() to run again.
     *
     * Removes any previously loaded env vars from putenv before clearing
     * the internal tracking, so immutable repositories don't see stale values.
     */
    public static function reset(): void
    {
        if (static::$cachedValues !== null) {
            foreach (static::$cachedValues as $name => $value) {
                static::getAdapter()->delete($name);
            }
        }

        static::$cachedValues = null;
        static::$dotenv = null;
        static::$adapter = null;
    }

    /**
     * Get or create the Dotenv instance.
     */
    protected static function getDotenv(array $paths, bool $force = false): Dotenv
    {
        if (static::$dotenv !== null && ! $force) {
            return static::$dotenv;
        }

        return static::$dotenv = Dotenv::create(
            RepositoryBuilder::createWithNoAdapters()
                ->addAdapter(static::getAdapter($force))
                ->immutable()
                ->make(),
            $paths
        );
    }

    /**
     * Get or create the environment adapter.
     */
    protected static function getAdapter(bool $force = false): AdapterInterface
    {
        if (static::$adapter !== null && ! $force) {
            return static::$adapter;
        }

        return static::$adapter = PutenvAdapter::create()->get();
    }
}
