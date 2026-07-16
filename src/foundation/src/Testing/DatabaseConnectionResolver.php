<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\FlushableConnectionResolver;
use LogicException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Database connection resolver for the testing environment.
 *
 * Testbench needs stable bare connections across its setup, transaction, and
 * assertion helpers. The resolver therefore retains each borrowed wrapper
 * alongside its bare connection and explicitly discards both at teardown.
 */
class DatabaseConnectionResolver extends ConnectionResolver implements FlushableConnectionResolver
{
    /**
     * Connections for testing environment.
     *
     * @var array<string, ConnectionInterface>
     */
    protected static array $connections = [];

    /**
     * Borrowed pooled wrappers that own the cached bare connections.
     *
     * @var array<string, PoolConnectionInterface>
     */
    protected static array $pooledConnections = [];

    /**
     * The object ID of the container when connections were cached.
     * Used to detect when a new test's container differs from previous.
     */
    protected static ?int $containerId = null;

    /**
     * Whether the dispatcher rebinding hook has been registered.
     */
    protected static bool $rebindingRegistered = false;

    /**
     * Reset all cached connections to clean state for another setup phase.
     *
     * Called after Application is created to prevent test pollution (query logs,
     * event listeners, transaction state, etc.) from leaking between tests.
     *
     * When the container changes (new test with fresh Application), cached
     * connections are flushed since they hold references to the old container's
     * services. A rebinding hook is registered so Event::fake() automatically
     * updates cached connections with the new dispatcher.
     *
     * Tests only. The cached connections are process-global, so runtime use can
     * reset or discard a connection borrowed by another coroutine.
     */
    public static function resetCachedConnections(): void
    {
        $container = Container::getInstance();
        $currentContainerId = spl_object_id($container);

        if (static::$containerId !== $currentContainerId) {
            static::discardCachedConnections();
            static::$containerId = $currentContainerId;
            static::$rebindingRegistered = false;
        }

        foreach (static::$connections as $connection) {
            if ($connection instanceof Connection) {
                $connection->resetForPool();
            }
        }

        static::registerDispatcherRebinding($container);
    }

    /**
     * Discard all cached connections and clear resolver lifecycle state.
     *
     * Tests only. The cached connections are process-global, so runtime use can
     * discard a connection borrowed by another coroutine.
     */
    public static function flushCachedConnections(): void
    {
        static::discardCachedConnections();
        static::$containerId = null;
        static::$rebindingRegistered = false;
    }

    /**
     * Register a rebinding hook for the event dispatcher.
     *
     * When Event::fake() swaps the dispatcher, this callback updates all
     * cached connections to use the new (fake) dispatcher.
     */
    protected static function registerDispatcherRebinding(ContainerContract $container): void
    {
        if (static::$rebindingRegistered) {
            return;
        }

        // Must use the canonical binding key. rebinding() resolves aliases when
        // storing callbacks, but instance() doesn't when firing them. Using the
        // canonical key avoids the mismatch.
        /** @var \Hypervel\Container\Container $container */
        $container->rebinding(Dispatcher::class, function ($app, $dispatcher) {
            foreach (static::$connections as $connection) {
                if ($connection instanceof Connection && $dispatcher instanceof Dispatcher) {
                    $connection->setEventDispatcher($dispatcher);
                }
            }
        });

        static::$rebindingRegistered = true;
    }

    /**
     * Flush a cached connection.
     *
     * Discard the owning pooled wrapper before clearing the bare connection.
     */
    public function flush(string $name): void
    {
        try {
            if (isset(static::$pooledConnections[$name])) {
                static::$pooledConnections[$name]->discard();
            }
        } finally {
            unset(static::$pooledConnections[$name], static::$connections[$name]);
        }
    }

    /**
     * Get a database connection instance.
     *
     * Creates connections through the pool factory and retains their owning
     * wrappers until terminal test teardown.
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

        // If the pool is enabled, we should use the default connection resolver.
        /** @var ConfigRepository $config */
        $config = $this->container->make('config');
        $poolEnabled = $config->boolean("database.connections.{$connectionName->base}.pool.testing_enabled", false);
        if ($poolEnabled) {
            return parent::connection($connectionName->requested);
        }

        if ($connection = static::$connections[$connectionName->requested] ?? null) {
            if ($connectionName->isWrite() && $connection instanceof Connection) {
                // resetCachedConnections() runs resetForPool() between tests and clears this flag.
                $connection->useWriteConnectionWhenReading();
            }

            return $connection;
        }

        $pooled = $this->factory->getPool($connectionName->requested)->get();

        try {
            $connection = $pooled->getConnection();

            if (! $connection instanceof ConnectionInterface) {
                throw new LogicException('The database pool returned an invalid connection.');
            }
        } catch (Throwable $exception) {
            $pooled->discard();

            throw $exception;
        }

        if ($connectionName->isWrite() && $connection instanceof Connection) {
            $connection->useWriteConnectionWhenReading();
        }

        static::$pooledConnections[$connectionName->requested] = $pooled;

        return static::$connections[$connectionName->requested] = $connection;
    }

    /**
     * Discard every cached pooled wrapper.
     */
    protected static function discardCachedConnections(): void
    {
        $exception = null;

        foreach (static::$pooledConnections as $pooled) {
            try {
                $pooled->discard();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        static::$pooledConnections = [];
        static::$connections = [];

        if ($exception !== null) {
            throw $exception;
        }
    }
}
