<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Support\Attributes\BootOnly;

class DatabaseCliManager
{
    /**
     * The registered database client resolvers.
     *
     * @var array<string, callable(array): DatabaseCliConfiguration>
     */
    protected array $extensions = [];

    /**
     * Register a database client resolver.
     *
     * The resolver persists on the auto-singleton manager for the worker
     * lifetime and applies to every subsequent database CLI session.
     *
     * @param callable(array): DatabaseCliConfiguration $resolver
     */
    #[BootOnly(whenShared: true)]
    public function extend(string $driver, callable $resolver): void
    {
        $this->extensions[$driver] = $resolver;
    }

    /**
     * Resolve a registered database client configuration.
     */
    public function resolve(array $connection): ?DatabaseCliConfiguration
    {
        if (! isset($this->extensions[$connection['driver']])) {
            return null;
        }

        return ($this->extensions[$connection['driver']])($connection);
    }
}
