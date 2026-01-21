<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use Psr\Container\ContainerInterface;
use UnitEnum;

use function Hyperf\Coroutine\defer;
use function Hypervel\Support\enum_value;

/**
 * Resolves database connections from a connection pool.
 *
 * Uses Hyperf's Context to store connections per-coroutine and defer()
 * to automatically release connections back to the pool when the
 * coroutine ends.
 */
class ConnectionResolver implements ConnectionResolverInterface
{
    /**
     * The default connection name.
     */
    protected string $default = 'default';

    protected PoolFactory $factory;

    public function __construct(
        protected ContainerInterface $container
    ) {
        $this->factory = $container->get(PoolFactory::class);
    }

    /**
     * Get a database connection instance.
     *
     * The connection is retrieved from a pool and stored in the current
     * coroutine's context. When the coroutine ends, the connection is
     * automatically released back to the pool.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();
        $contextKey = $this->getContextKey($name);

        // Check if this coroutine already has a connection
        if (Context::has($contextKey)) {
            $connection = Context::get($contextKey);
            if ($connection instanceof ConnectionInterface) {
                return $connection;
            }
        }

        // Get a pooled connection wrapper from the pool
        $pool = $this->factory->getPool($name);

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            // Get the actual database connection from the wrapper
            $connection = $pooledConnection->getConnection();

            // Store in context for this coroutine
            Context::set($contextKey, $connection);
        } finally {
            // Schedule cleanup when coroutine ends
            if (Coroutine::inCoroutine()) {
                defer(function () use ($pooledConnection, $contextKey) {
                    Context::set($contextKey, null);
                    $pooledConnection->release();
                });
            }
        }

        return $connection;
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return $this->default;
    }

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->default = $name;
    }

    /**
     * Get the context key for storing a connection.
     */
    protected function getContextKey(string $name): string
    {
        return sprintf('database.connection.%s', $name);
    }
}
