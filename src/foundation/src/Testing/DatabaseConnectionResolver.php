<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\FlushableConnectionResolver;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Database connection resolver for the testing environment.
 *
 * Uses a hybrid lifecycle model: connections are created through the real
 * pool infrastructure (same factory, config, and connection path as production)
 * but cached statically instead of using the pool's checkout/release cycle.
 *
 * This is intentional — tests don't run in coroutines with defer(), so the
 * normal pool lifecycle (checkout → defer release → coroutine ends) doesn't
 * apply. Instead, connections are cached per-name and reused across test
 * methods within the same worker process.
 *
 * The PooledConnection wrapper is discarded after extracting the bare
 * Connection. This means pool release() never runs on the wrapper, but
 * that's acceptable because:
 * - flushCachedConnections() handles per-test state reset (resetForPool)
 * - flush() / flushCachedConnections() disconnect PDOs to prevent leaks
 * - DB::purge() flushes the entire pool when connection config changes
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
     * The object ID of the container when connections were cached.
     * Used to detect when a new test's container differs from previous.
     */
    protected static ?int $containerId = null;

    /**
     * Whether the dispatcher rebinding hook has been registered.
     */
    protected static bool $rebindingRegistered = false;

    /**
     * Flush all cached connections to clean state.
     *
     * Called after Application is created to prevent test pollution (query logs,
     * event listeners, transaction state, etc.) from leaking between tests.
     *
     * When the container changes (new test with fresh Application), cached
     * connections are flushed since they hold references to the old container's
     * services. A rebinding hook is registered so Event::fake() automatically
     * updates cached connections with the new dispatcher.
     */
    public static function flushCachedConnections(): void
    {
        $container = Container::getInstance();
        $currentContainerId = spl_object_id($container);

        // If container changed, disconnect and flush all cached connections since
        // they hold stale references to the old container's dispatcher and other services
        if (static::$containerId !== $currentContainerId) {
            static::$containerId = $currentContainerId;

            foreach (static::$connections as $connection) {
                if ($connection instanceof Connection) {
                    $connection->disconnect();
                }
            }

            static::$connections = [];
            static::$rebindingRegistered = false;
        }

        // Reset per-request state on remaining connections
        foreach (static::$connections as $connection) {
            if ($connection instanceof Connection) {
                $connection->resetForPool();
            }
        }

        // Register rebinding hook so Event::fake() updates cached connections
        static::registerDispatcherRebinding($container);
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
     * Disconnects the underlying PDO before clearing the cache, ensuring
     * the database connection is properly closed rather than orphaned.
     */
    public function flush(string $name): void
    {
        if (isset(static::$connections[$name]) && static::$connections[$name] instanceof Connection) {
            static::$connections[$name]->disconnect();
        }

        unset(static::$connections[$name]);
    }

    /**
     * Get a database connection instance.
     *
     * Creates connections through the pool factory so they use the same
     * configuration and creation path as production, then caches the bare
     * Connection statically. The PooledConnection wrapper is intentionally
     * discarded — see the class docblock for the reasoning.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();

        // If the pool is enabled, we should use the default connection resolver.
        $poolEnabled = $this->container
            ->get('config')
            ->get("database.connections.{$name}.pool.testing_enabled", false);
        if ($poolEnabled) {
            return parent::connection($name);
        }

        if ($connection = static::$connections[$name] ?? null) {
            return $connection;
        }

        // Check out from pool, extract bare Connection, discard the wrapper.
        // The wrapper's release() is not needed — see class docblock.
        return static::$connections[$name] = $this->factory
            ->getPool($name)
            ->get()
            ->getConnection();
    }
}
