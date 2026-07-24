<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolFactory;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Resolves database connections from a connection pool.
 *
 * Retains pooled wrappers until their owning coroutine or task ends.
 */
class ConnectionResolver implements ConnectionResolverInterface
{
    /**
     * Context key for per-coroutine default connection override.
     *
     * Shared with DatabaseManager::usingConnection() to ensure all access
     * paths respect the override.
     */
    public const DEFAULT_CONNECTION_CONTEXT_KEY = '__database.default_connection';

    /**
     * The config-derived default connection name, captured at construction.
     *
     * Serves as the fallback for getDefaultConnection() when no coroutine
     * Context override is active. Readonly because runtime overrides go
     * through CoroutineContext, not through this property.
     */
    protected readonly ?string $default;

    protected PoolFactory $factory;

    /**
     * Pooled wrappers retained by non-coroutine task execution.
     *
     * @var array<string, PooledConnection>
     */
    protected array $nonCoroutineConnections = [];

    public function __construct(
        protected Container $container
    ) {
        $this->factory = $container->make(PoolFactory::class);
        $this->default = $container->make('config')->string('database.default', 'default');
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
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultConnection()
            : $name;

        $connectionName = ConnectionName::parse($name);
        $connectionOwnerName = $connectionName->requested;
        $contextKey = $this->getContextKey($connectionOwnerName);

        // Check if this coroutine already has a connection
        if (CoroutineContext::has($contextKey)) {
            $connection = CoroutineContext::get($contextKey);
            if ($connection instanceof ConnectionInterface) {
                return $connection;
            }
        }

        // Get a pooled connection wrapper from the pool
        $pool = $this->factory->getPool($connectionName->requested);

        // Role aliases of one shared in-memory PDO must share its sole wrapper owner.
        if ($pool->getSharedInMemorySqlitePdo() !== null) {
            $connectionOwnerName = $pool->getName();
            $contextKey = $this->getContextKey($connectionOwnerName);

            if (CoroutineContext::has($contextKey)) {
                $connection = CoroutineContext::get($contextKey);

                if ($connection instanceof ConnectionInterface) {
                    if ($connectionName->isWrite() && $connection instanceof Connection) {
                        $connection->useWriteConnectionWhenReading();
                    }

                    return $connection;
                }
            }
        }

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();

            if ($connectionName->isWrite() && $connection instanceof Connection) {
                $connection->useWriteConnectionWhenReading();
            }

            CoroutineContext::set($contextKey, $connection);

            if (Coroutine::inCoroutine()) {
                Coroutine::defer(function () use ($pooledConnection, $contextKey): void {
                    CoroutineContext::forget($contextKey);
                    $pooledConnection->release();
                });
            } else {
                $this->nonCoroutineConnections[$connectionOwnerName] = $pooledConnection;
            }
        } catch (Throwable $exception) {
            CoroutineContext::forget($contextKey);
            unset($this->nonCoroutineConnections[$connectionOwnerName]);

            try {
                $pooledConnection->discard();
            } catch (Throwable) {
                // Preserve the connection-creation or publication failure.
            }

            throw $exception;
        }

        return $connection;
    }

    /**
     * Release connections retained by non-coroutine task execution.
     *
     * @internal
     */
    public function releaseConnections(): void
    {
        $this->terminateConnections(
            static function (PooledConnection $connection): void {
                $connection->release();
            },
        );
    }

    /**
     * Discard connections retained by non-coroutine task execution.
     *
     * @internal
     */
    public function discardConnections(): void
    {
        $this->terminateConnections(
            static function (PooledConnection $connection): void {
                $connection->discard();
            },
        );
    }

    /**
     * Get the default connection name.
     *
     * Checks Context first for per-coroutine override (from setDefaultConnection()
     * or DatabaseManager::usingConnection()), then falls back to the
     * config-derived default captured at construction.
     */
    public function getDefaultConnection(): ?string
    {
        return CoroutineContext::get(self::DEFAULT_CONNECTION_CONTEXT_KEY) ?? $this->default;
    }

    /**
     * Set the default connection name for the current execution context.
     *
     * Writes to coroutine Context so concurrent requests in the same Swoole
     * worker are not affected. A null value clears the override and
     * getDefaultConnection() falls back to the config-derived default.
     */
    public function setDefaultConnection(?string $name): void
    {
        if ($name === null) {
            CoroutineContext::forget(self::DEFAULT_CONNECTION_CONTEXT_KEY);
        } else {
            CoroutineContext::set(self::DEFAULT_CONNECTION_CONTEXT_KEY, $name);
        }
    }

    /**
     * Get the context key for storing a connection.
     */
    protected function getContextKey(string $name): string
    {
        return sprintf('__database.connection.%s', $name);
    }

    /**
     * Detach and terminate retained non-coroutine connections.
     */
    protected function terminateConnections(Closure $terminate): void
    {
        $connections = $this->nonCoroutineConnections;
        $this->nonCoroutineConnections = [];

        foreach (array_keys($connections) as $name) {
            CoroutineContext::forget($this->getContextKey($name));
        }

        CoroutineContext::forget(self::DEFAULT_CONNECTION_CONTEXT_KEY);

        $exception = null;

        foreach ($connections as $connection) {
            try {
                $terminate($connection);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}
