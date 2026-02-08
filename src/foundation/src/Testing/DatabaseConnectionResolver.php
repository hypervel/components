<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\FlushableConnectionResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Database connection resolver for the testing environment.
 *
 * Caches connections statically to prevent pool exhaustion (since the testing
 * environment doesn't use defer() to release connections back to the pool).
 * Call resetCachedConnections() at the start of each test to ensure clean
 * state without the test pollution that static caching would otherwise cause.
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
     * Reset all cached connections to clean state.
     *
     * Called after Application is created to prevent test pollution (query logs,
     * event listeners, transaction state, etc.) from leaking between tests.
     *
     * When the container changes (new test with fresh Application), cached
     * connections are flushed since they hold references to the old container's
     * services. A rebinding hook is registered so Event::fake() automatically
     * updates cached connections with the new dispatcher.
     */
    public static function resetCachedConnections(): void
    {
        $container = ApplicationContext::getContainer();
        $currentContainerId = spl_object_id($container);

        // If container changed, flush all cached connections since they hold
        // stale references to the old container's dispatcher and other services
        if (static::$containerId !== $currentContainerId) {
            static::$containerId = $currentContainerId;
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
    protected static function registerDispatcherRebinding(Container $container): void
    {
        if (static::$rebindingRegistered) {
            return;
        }

        // Register for the PSR interface that Event facade uses
        $container->rebinding(EventDispatcherInterface::class, function ($app, $dispatcher) {
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
     * Clears the static cache so the next connection() call creates a fresh
     * connection with current configuration.
     */
    public function flush(string $name): void
    {
        unset(static::$connections[$name]);
    }

    /**
     * Get a database connection instance.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();

        // If the pool is enabled, we should use the default connection resolver.
        $poolEnabled = $this->container
            ->get(ConfigInterface::class)
            ->get("database.connections.{$name}.pool.testing_enabled", false);
        if ($poolEnabled) {
            return parent::connection($name);
        }

        if ($connection = static::$connections[$name] ?? null) {
            return $connection;
        }

        return static::$connections[$name] = $this->factory
            ->getPool($name)
            ->get()
            ->getConnection();
    }
}
