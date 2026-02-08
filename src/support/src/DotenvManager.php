<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\AdapterInterface;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

class DotenvManager
{
    protected static AdapterInterface $adapter;

    protected static Dotenv $dotenv;

    protected static array $cachedValues;

    /**
     * Load environment variables from the given paths.
     */
    public static function load(array $paths): void
    {
        if (isset(static::$cachedValues)) {
            return;
        }

        static::$cachedValues = static::getDotenv($paths)->load();
    }

    /**
     * Reload environment variables from the given paths.
     */
    public static function reload(array $paths, bool $force = false): void
    {
        if (! isset(static::$cachedValues)) {
            static::load($paths);

            return;
        }

        foreach (static::$cachedValues as $deletedEntry => $value) {
            static::getAdapter()->delete($deletedEntry);
        }

        static::$cachedValues = static::getDotenv($paths, $force)->load();
    }

    /**
     * Get or create the Dotenv instance.
     */
    protected static function getDotenv(array $paths, bool $force = false): Dotenv
    {
        if (isset(static::$dotenv) && ! $force) {
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
        if (isset(static::$adapter) && ! $force) {
            return static::$adapter;
        }

        return static::$adapter = PutenvAdapter::create()->get();
    }
}
