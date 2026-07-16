<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Support\Arr;
use Hypervel\Support\Fluent;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Database\Connection
 */
class DatabaseManager implements ConnectionResolverInterface
{
    use InteractsWithTime;
    use Macroable {
        __call as macroCall;
    }

    /**
     * Context key for query duration handlers that have run in the current coroutine.
     */
    public const QUERY_DURATION_HANDLERS_CONTEXT_KEY = '__database.query_duration_handlers';

    /**
     * The active connection instances.
     *
     * Note: In Hypervel's pooled connection mode, connections are stored
     * per-coroutine in Context, not in this array. This property exists
     * for Laravel API compatibility but is not populated during normal
     * pooled operation.
     *
     * @var array<string, \Hypervel\Database\Connection>
     */
    protected array $connections = [];

    /**
     * The callback to be executed to reconnect to a database.
     */
    protected Closure $reconnector;

    /**
     * All registered query duration handlers.
     *
     * @var array<int, array{connection: string, key: string, threshold: float|int, handler: callable}>
     */
    protected array $queryDurationHandlers = [];

    /**
     * Indicates if the manager-level query duration listener has been registered.
     */
    protected bool $queryDurationListenerRegistered = false;

    /**
     * Create a new database manager instance.
     */
    public function __construct(
        protected ContainerContract $app,
        protected ConnectionFactory $factory
    ) {
        $this->reconnector = function (Connection $connection) {
            $name = $connection->getName();

            if ($name !== null && $connection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY) === ConnectionName::READ) {
                $name .= '::' . ConnectionName::READ;
            }

            $connection->setPdo(
                $this->reconnect($name)->getRawPdo()
            );
        };
    }

    /**
     * Get a database connection instance.
     *
     * Delegates to ConnectionResolver for pooled, per-coroutine connection management.
     * Resolves the default connection name here (checking Context for usingConnection override)
     * before passing to the resolver.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultConnection()
            : $name;

        return $this->app->make('db.resolver')->connection($name);
    }

    /**
     * Resolve a connection directly without using the connection pool.
     *
     * This method is used by SimpleConnectionResolver for testing and Capsule
     * environments where connection pooling is not needed. It manages connections
     * in the $connections array like Laravel's original DatabaseManager.
     *
     * @internal For use by SimpleConnectionResolver only
     */
    public function resolveConnectionDirectly(string $name): ConnectionInterface
    {
        $connectionName = ConnectionName::parse($name);

        if (! isset($this->connections[$connectionName->requested])) {
            $connection = $this->configure(
                $this->makeConnection($connectionName)
            );

            if ($connectionName->isWrite()) {
                $connection->useWriteConnectionWhenReading();
            }

            $this->connections[$connectionName->requested] = $connection;

            $this->dispatchConnectionEstablishedEvent($connection);
        }

        return $this->connections[$connectionName->requested];
    }

    /**
     * Build a database connection instance from the given configuration.
     *
     * @throws RuntimeException Always - dynamic connections not supported in Hypervel
     */
    public function build(array $config): ConnectionInterface
    {
        throw new RuntimeException(
            'Dynamic database connections via DB::build() are not supported in Hypervel. '
            . 'Configure all connections in config/database.php instead.'
        );
    }

    /**
     * Get a database connection instance from the given configuration.
     *
     * @throws RuntimeException Always - dynamic connections not supported in Hypervel
     */
    public function connectUsing(string $name, array $config, bool $force = false): ConnectionInterface
    {
        throw new RuntimeException(
            'Dynamic database connections via DB::connectUsing() are not supported in Hypervel. '
            . 'Configure all connections in config/database.php instead.'
        );
    }

    /**
     * Make the database connection instance.
     */
    protected function makeConnection(ConnectionName|string $name): Connection
    {
        $connectionName = is_string($name) ? ConnectionName::parse($name) : $name;
        $config = $this->configuration($connectionName);

        return $this->factory->make($config, $connectionName->base);
    }

    /**
     * Get the configuration for a connection.
     *
     * @throws InvalidArgumentException
     */
    protected function configuration(ConnectionName|string $name): array
    {
        $connectionName = is_string($name) ? ConnectionName::parse($name) : $name;

        /** @var array<string, array> $connections */
        $connections = $this->configValue('database.connections', []);

        $config = Arr::get($connections, $connectionName->base);

        if (is_null($config)) {
            throw new InvalidArgumentException("Database connection [{$connectionName->base}] not configured.");
        }

        $config = $this->factory->parseConfig($config, $connectionName->base);

        if ($connectionName->isRead() && $this->factory->hasReadConfig($config)) {
            return $this->factory->configForRead($config);
        }

        return $config;
    }

    /**
     * Prepare the database connection instance.
     */
    protected function configure(Connection $connection): Connection
    {
        // Set the event dispatcher if available.
        if ($this->app->bound('events')) {
            $connection->setEventDispatcher($this->app->make('events'));
        }

        if ($this->app->bound('db.transactions')) {
            $connection->setTransactionManager($this->app->make('db.transactions'));
        }

        // Set a reconnector callback to reconnect from this manager with the name of
        // the connection, which will allow us to reconnect from the connections.
        $connection->setReconnector($this->reconnector);

        return $connection;
    }

    /**
     * Dispatch the ConnectionEstablished event if the event dispatcher is available.
     */
    protected function dispatchConnectionEstablishedEvent(Connection $connection): void
    {
        if (! $this->app->bound('events')) {
            return;
        }

        $this->app->make('events')->dispatch(
            new ConnectionEstablished($connection)
        );
    }

    /**
     * Disconnect from the given database and flush its pool.
     *
     * In pooled mode, this disconnects the current coroutine's connection,
     * clears its context key (so the next connection() call gets a fresh
     * pooled connection), and flushes the pool. Use this when connection
     * configuration has changed and you need to fully reset.
     *
     * Boot or tests only. Flushes the shared pool used by every coroutine;
     * concurrent coroutines holding pooled connections will release them back
     * into a discarded pool, and the current coroutine may briefly hold two
     * pooled connections (the old one releases via defer at coroutine end).
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $requestedName = $name === null || $name === ''
            ? $this->getDefaultConnection()
            : $name;
        $connectionName = ConnectionName::parse($requestedName);
        $variants = $this->connectionNameVariants($requestedName);

        foreach ($variants as $variant) {
            // Disconnect current connection if any
            $this->disconnect($variant);
        }

        foreach ($variants as $variant) {
            // Clear context so next connection() gets a fresh pooled connection
            CoroutineContext::forget($this->getConnectionContextKey($variant));

            // Clear cached connection for SimpleConnectionResolver (non-pooled mode)
            unset($this->connections[$variant]);
        }

        // Clear resolver-level caching (e.g., DatabaseConnectionResolver's static cache)
        $resolver = $this->app->make('db.resolver');
        if ($resolver instanceof FlushableConnectionResolver) {
            foreach ($variants as $variant) {
                $resolver->flush($variant);
            }
        }

        // Flush the pool to honor config changes
        if ($this->app->has(PoolFactory::class)) {
            $this->app->make(PoolFactory::class)->flushPoolsForConnection($connectionName->base);
        }
    }

    /**
     * Disconnect from the given database.
     *
     * In pooled mode, this nulls the PDOs on the current coroutine's connection
     * (if one exists), forcing a reconnect on the next query. Does not clear
     * context or affect the pool - the connection is still released at coroutine end.
     *
     * In non-pooled mode (SimpleConnectionResolver), disconnects the connection
     * stored in the $connections array.
     */
    public function disconnect(UnitEnum|string|null $name = null): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $requestedName = $name === null || $name === ''
            ? $this->getDefaultConnection()
            : $name;
        $connectionName = ConnectionName::parse($requestedName);

        // Pooled mode: disconnect the current coroutine's connection
        $connection = CoroutineContext::get($this->getConnectionContextKey($connectionName->requested));
        if ($connection instanceof Connection) {
            $connection->disconnect();
        }

        // Non-pooled mode (SimpleConnectionResolver): disconnect from $connections array
        if (isset($this->connections[$connectionName->requested])) {
            $this->connections[$connectionName->requested]->disconnect();
        }
    }

    /**
     * Reconnect to the given database.
     *
     * In pooled mode, if this coroutine already has a connection, reconnects
     * its PDOs and returns it. In non-pooled mode, refreshes the existing
     * connection's PDOs in-place. Otherwise gets a fresh connection.
     */
    public function reconnect(UnitEnum|string|null $name = null): Connection
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultConnection()
            : $name;

        $this->disconnect($name);

        // Pooled mode: if we already have a connection in this coroutine, reconnect it
        $contextKey = $this->getConnectionContextKey($name);
        $connection = CoroutineContext::get($contextKey);
        if ($connection instanceof Connection) {
            $connection->reconnect();
            $this->dispatchConnectionEstablishedEvent($connection);

            return $connection;
        }

        // Non-pooled mode: refresh PDOs on existing connection in-place
        if (isset($this->connections[$name])) {
            return tap($this->refreshPdoConnections($name), function ($connection) {
                $this->dispatchConnectionEstablishedEvent($connection);
            });
        }

        // No existing connection — get a fresh one
        // @phpstan-ignore return.type (connection() returns ConnectionInterface but concrete Connection in practice)
        return $this->connection($name);
    }

    /**
     * Set the default database connection for the callback execution.
     *
     * Uses Context for coroutine-safe state management, ensuring concurrent
     * requests don't interfere with each other's default connection.
     */
    public function usingConnection(UnitEnum|string $name, callable $callback): mixed
    {
        $previous = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        CoroutineContext::set(
            ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY,
            $name instanceof UnitEnum ? (string) enum_value($name) : $name
        );

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previous);
            }
        }
    }

    /**
     * Refresh the PDO connections on a given connection.
     */
    protected function refreshPdoConnections(string $name): Connection
    {
        $fresh = $this->configure(
            $this->makeConnection($name)
        );

        return $this->connections[$name]
            ->setPdo($fresh->getRawPdo())
            ->setReadPdo($fresh->getRawReadPdo());
    }

    /**
     * Get the default connection name.
     *
     * Checks Context first for per-coroutine override (from usingConnection()),
     * then falls back to the global config default.
     */
    public function getDefaultConnection(): ?string
    {
        /** @var null|string $defaultConnection */
        $defaultConnection = $this->configValue('database.default');

        return CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY)
            ?? $defaultConnection;
    }

    /**
     * Register a callback to be invoked when the current connection queries for longer than a given amount of time.
     *
     * Boot-only. The callback persists on the database manager for the worker lifetime and affects every subsequent request for the same connection.
     */
    public function whenQueryingForLongerThan(DateTimeInterface|CarbonInterval|float|int $threshold, callable $handler): void
    {
        $connectionName = $this->getEffectiveConnectionName();
        $key = count($this->queryDurationHandlers);

        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->queryDurationHandlers[] = [
            'connection' => $connectionName,
            'key' => $this->queryDurationHandlerKey($key, $connectionName),
            'threshold' => $threshold,
            'handler' => $handler,
        ];

        $this->registerQueryDurationListener();
    }

    /**
     * Allow all the query duration handlers to run again for the current connection.
     */
    public function allowQueryDurationHandlersToRunAgain(): void
    {
        $connectionName = $this->getEffectiveConnectionName();
        /** @var array<string, true> $ranHandlers */
        $ranHandlers = CoroutineContext::get(self::QUERY_DURATION_HANDLERS_CONTEXT_KEY, []);

        foreach ($this->queryDurationHandlers as $config) {
            if ($config['connection'] === $connectionName) {
                unset($ranHandlers[$config['key']]);
            }
        }

        CoroutineContext::set(self::QUERY_DURATION_HANDLERS_CONTEXT_KEY, $ranHandlers);

        /** @var Connection $connection */
        $connection = $this->connection($connectionName);
        $connection->allowQueryDurationHandlersToRunAgain();
    }

    /**
     * Set the default connection name for the current execution context.
     *
     * Writes to coroutine Context so concurrent requests in the same Swoole
     * worker are not affected. A null value clears the override and
     * getDefaultConnection() falls back to config('database.default').
     */
    public function setDefaultConnection(?string $name): void
    {
        if ($name === null) {
            CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        } else {
            CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $name);
        }
    }

    /**
     * Get a config value.
     */
    protected function configValue(string $key, mixed $default = null): mixed
    {
        /** @var ConfigRepository|Fluent $config */
        $config = $this->app->make('config');

        return $config[$key] ?? $default;
    }

    /**
     * Get the context key for storing a connection.
     *
     * Uses the same format as ConnectionResolver for consistency.
     */
    protected function getConnectionContextKey(string $name): string
    {
        return sprintf('__database.connection.%s', $name);
    }

    /**
     * Get all coroutine/cache variants for a connection name.
     *
     * @return string[]
     */
    protected function connectionNameVariants(UnitEnum|string|null $name): array
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === ''
            ? $this->getDefaultConnection()
            : $name;

        $base = ConnectionName::parse($name)->base;

        return [$base, $base . '::read', $base . '::write'];
    }

    /**
     * Get the current effective connection name.
     */
    protected function getEffectiveConnectionName(): string
    {
        return $this->getDefaultConnection();
    }

    /**
     * Register the manager-level query duration listener.
     */
    protected function registerQueryDurationListener(): void
    {
        if ($this->queryDurationListenerRegistered || ! $this->app->bound('events')) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $this->app->make('events');

        $events->listen(QueryExecuted::class, function (QueryExecuted $event) {
            $matchingHandlers = [];

            foreach ($this->queryDurationHandlers as $config) {
                if ($event->connectionName === $config['connection']) {
                    $matchingHandlers[] = $config;
                }
            }

            if ($matchingHandlers === []) {
                return;
            }

            /** @var array<string, true> $ranHandlers */
            $ranHandlers = CoroutineContext::get(self::QUERY_DURATION_HANDLERS_CONTEXT_KEY, []);
            $handlers = [];

            foreach ($matchingHandlers as $config) {
                if (! isset($ranHandlers[$config['key']]) && $event->connection->totalQueryDuration() > $config['threshold']) {
                    $ranHandlers[$config['key']] = true;
                    $handlers[] = $config['handler'];
                }
            }

            if ($handlers === []) {
                return;
            }

            CoroutineContext::set(self::QUERY_DURATION_HANDLERS_CONTEXT_KEY, $ranHandlers);

            foreach ($handlers as $handler) {
                $handler($event->connection, $event);
            }
        });

        $this->queryDurationListenerRegistered = true;
    }

    /**
     * Get the coroutine-local key for a query duration handler registration.
     */
    protected function queryDurationHandlerKey(int $key, string $connectionName): string
    {
        return $connectionName . ':' . $key;
    }

    /**
     * Get all of the supported drivers.
     *
     * @return string[]
     */
    public function supportedDrivers(): array
    {
        return ['mysql', 'mariadb', 'pgsql', 'sqlite'];
    }

    /**
     * Get all of the drivers that are actually available.
     *
     * @return string[]
     */
    public function availableDrivers(): array
    {
        return array_intersect(
            $this->supportedDrivers(),
            PDO::getAvailableDrivers()
        );
    }

    /**
     * Register an extension connection resolver.
     *
     * Extensions are stored on the ConnectionFactory so they are consulted
     * by both the pooled path (PooledConnection → factory) and the non-pooled
     * path (DatabaseManager → factory).
     *
     * Boot-only. The resolver persists on the singleton ConnectionFactory for
     * the worker lifetime and applies to every subsequent connection.
     */
    public function extend(string $name, callable $resolver): void
    {
        $this->factory->extend($name, $resolver);
    }

    /**
     * Remove an extension connection resolver.
     *
     * Boot or tests only. Mutates the singleton ConnectionFactory's extension
     * registry; concurrent coroutines establishing connections may see the
     * resolver removed mid-resolution.
     */
    public function forgetExtension(string $name): void
    {
        $this->factory->forgetExtension($name);
    }

    /**
     * Return all of the created connections.
     *
     * Note: In Hypervel's pooled connection mode, connections are stored
     * per-coroutine in Context rather than in this array. This method
     * returns an empty array in normal pooled operation. Use the pool
     * infrastructure to inspect active connections if needed.
     *
     * @return array<string, Connection>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Purge all connections on the current manager instance.
     *
     * Resolves the DatabaseManager from the container and disconnects
     * every active connection. Safe to call when no container or no
     * database manager exists (e.g., unit tests).
     *
     * Uses bound() - not has() - because Application::has() falls through to
     * class_exists(), so any string that case-insensitively matches a loaded
     * PHP class name would falsely report bound. bound() only checks
     * bindings/instances/aliases.
     *
     * Boot or tests only. Calls purge() on every connection (flushes the
     * shared pools); concurrent coroutines lose their cached connection
     * references mid-request.
     */
    public static function purgeConnections(): void
    {
        $container = Container::getInstance();

        if (! $container->bound('db')) {
            return;
        }

        /** @var static $db */
        $db = $container->make('db');

        foreach (array_keys($db->getConnections()) as $name) {
            $db->purge($name);
        }
    }

    /**
     * Set the database reconnector callback.
     *
     * Boot-only. The reconnector persists on the singleton DatabaseManager for
     * the worker lifetime and is invoked for every disconnected connection
     * across all coroutines.
     */
    public function setReconnector(callable $reconnector): void
    {
        $this->reconnector = $reconnector;
    }

    /**
     * Set the application instance used by the manager.
     *
     * Tests only. Swaps the singleton's application reference; per-request use
     * races across coroutines and breaks every concurrent database operation.
     */
    public function setApplication(Application $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }

    /**
     * Dynamically pass methods to the default connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->connection()->{$method}(...$parameters);
    }
}
