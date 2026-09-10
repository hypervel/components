<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\ConnectionPool\BorrowRateTracker;
use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\ConnectionPool\UsageTracker;
use Hypervel\Contracts\Container\Container;
use Hypervel\Coordinator\Timer;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use PDO;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Database connection pool.
 *
 * For in-memory SQLite, manages a shared PDO behind a single pooled owner.
 * Non-pooled paths (Capsule, SimpleConnectionResolver) bypass this entirely
 * and get isolated connections as expected.
 */
class DatabasePool extends ConnectionPool
{
    protected array $config;

    protected ?Timer $heartbeatTimer = null;

    protected ?int $heartbeatTimerId = null;

    protected bool $heartbeatStarted = false;

    /**
     * Shared PDO for in-memory SQLite.
     */
    protected ?PDO $sharedInMemorySqlitePdo = null;

    /**
     * Create a database connection pool.
     */
    public function __construct(Container $container, string $name)
    {
        $connectionName = ConnectionName::parse($name);
        $configService = $container->make('config');
        $key = sprintf('database.connections.%s', $connectionName->base);

        if (! $configService->has($key)) {
            throw new InvalidArgumentException(sprintf('Database connection [%s] not configured.', $connectionName->base));
        }

        /** @var array<string, mixed> $config */
        $config = $configService->get($key);

        /** @var ConnectionFactory $factory */
        $factory = $container->make('db.factory');
        $config = $factory->parseConfig($config, $connectionName->base);
        $poolConfig = $config;

        if ($connectionName->isRead() && $factory->hasReadConfig($config)) {
            $poolConfig = $factory->configForRead($config);
            $this->ensureNotDerivedInMemorySqlitePool($connectionName, $poolConfig);

            if ($factory->getExtension($config, $connectionName->base) === null) {
                $config = $poolConfig;
            } else {
                // Extensions own endpoint selection, but the pool still uses
                // the selected read record's pool options and SQLite metadata.
                $config[Connection::READ_WRITE_TYPE_CONFIG_KEY] = ConnectionName::READ;
            }
        }

        $this->config = $config;

        $poolOptions = Arr::except(
            Arr::get($poolConfig, 'pool', []),
            ['testing_enabled'],
        );

        $minimum = array_key_exists('min_retained_connections', $poolOptions) ? $poolOptions['min_retained_connections'] : 1;
        $maximum = array_key_exists('max_connections', $poolOptions) ? $poolOptions['max_connections'] : 10;

        if ($this->isInMemorySqlite($poolConfig)
            && is_int($minimum)
            && is_int($maximum)
            && $minimum >= 0
            && $maximum >= 1
            && $minimum <= $maximum
        ) {
            $poolOptions['min_retained_connections'] = min($minimum, 1);
            $poolOptions['max_connections'] = 1;
        }

        parent::__construct($container, $name, $poolOptions);
        $this->configureConnectTimeout();

        $this->heartbeatTimer = new Timer($this->getLogger());

        // The sole managed wrapper must retain one PDO for the database lifetime.
        if ($this->isInMemorySqlite($poolConfig)) {
            $this->sharedInMemorySqlitePdo = $this->createSharedInMemorySqlitePdo();
        }
    }

    /**
     * Enable background maintenance after pool initialization succeeds.
     */
    public function start(): void
    {
        parent::start();
        $this->startHeartbeat();
    }

    /**
     * Get the shared PDO for in-memory SQLite, or null for other drivers/configurations.
     */
    public function getSharedInMemorySqlitePdo(): ?PDO
    {
        return $this->sharedInMemorySqlitePdo;
    }

    /**
     * Create a new pooled connection.
     */
    protected function createConnection(): PoolConnection
    {
        return new PooledConnection($this->container, $this, $this->config);
    }

    /**
     * Expose the pool connection deadline to the database driver.
     */
    private function configureConnectTimeout(): void
    {
        $this->config['connect_timeout'] ??= $this->options->connectTimeout;
    }

    /**
     * Create the shared PDO for in-memory SQLite via the factory.
     *
     * Uses the normal PDO resolver pipeline so initial construction and
     * refresh produce the same connection subclass.
     */
    protected function createSharedInMemorySqlitePdo(): PDO
    {
        $factory = $this->container->make('db.factory');
        $connection = $factory->makeSharedInMemorySqliteConnection($this->config, $this->name);

        return $connection->getPdo();
    }

    /**
     * Create a usage policy for this database pool.
     */
    protected function createUsageTracker(): ?UsageTracker
    {
        return new BorrowRateTracker;
    }

    /**
     * Check if this pool is for an in-memory SQLite database.
     */
    protected function isInMemorySqlite(array $config): bool
    {
        if (($config['driver'] ?? '') !== 'sqlite') {
            return false;
        }

        $database = $config['database'] ?? '';

        return SQLiteDatabase::isInMemory($database);
    }

    /**
     * Ensure a derived read pool does not point at an in-memory SQLite database.
     */
    protected function ensureNotDerivedInMemorySqlitePool(ConnectionName $name, array $config): void
    {
        if (($config['driver'] ?? null) !== 'sqlite') {
            return;
        }

        $database = $config['database'] ?? '';

        if (SQLiteDatabase::isInMemory($database)) {
            throw new InvalidArgumentException(
                "Database connection [{$name->requested}] cannot use a derived read pool for in-memory SQLite."
            );
        }
    }

    /**
     * Close the database pool and clear its shared resources.
     */
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->clearHeartbeat();

        try {
            parent::close();
        } finally {
            $this->sharedInMemorySqlitePdo = null;
        }
    }

    /**
     * Start the heartbeat timer if configured.
     */
    protected function startHeartbeat(): void
    {
        if ($this->heartbeatStarted || $this->isClosed() || $this->heartbeatTimer === null
            || $this->options->heartbeatInterval === null || $this->sharedInMemorySqlitePdo !== null
        ) {
            return;
        }

        // Timer creation can reenter pool lifecycle methods through startup hooks.
        $this->heartbeatStarted = true;

        try {
            $timerId = $this->heartbeatTimer->tick(
                $this->options->heartbeatInterval,
                function (bool $isClosing): ?string {
                    if ($isClosing || $this->isClosed()) {
                        return Timer::STOP;
                    }

                    $this->heartbeat();

                    return null;
                }
            );
        } catch (Throwable $exception) {
            $this->heartbeatStarted = false;

            throw $exception;
        }

        if (! $this->heartbeatStarted || $this->isClosed()) {
            $this->heartbeatStarted = false;
            $this->heartbeatTimer->clear($timerId);

            return;
        }

        $this->heartbeatTimerId = $timerId;
    }

    /**
     * Clear the heartbeat timer.
     */
    protected function clearHeartbeat(): void
    {
        $timerId = $this->heartbeatTimerId;
        $this->heartbeatTimerId = null;
        $this->heartbeatStarted = false;

        if ($timerId !== null) {
            $this->heartbeatTimer?->clear($timerId);
        }
    }

    /**
     * Run one heartbeat sweep over currently idle connections.
     */
    protected function heartbeat(): void
    {
        $connectionsToInspect = $this->getIdleCount();

        for ($index = 0; $index < $connectionsToInspect; ++$index) {
            /** @var false|PooledConnection $connection */
            $connection = $this->popIdleConnection();

            if ($connection === false) {
                break;
            }

            $this->heartbeatConnection($connection);
        }
    }

    /**
     * Heartbeat one idle connection.
     */
    protected function heartbeatConnection(PooledConnection $connection): void
    {
        try {
            $now = hrtime(true) / 1e9;

            $expired = $connection->isLifetimeExpired($now)
                || ($connection->isIdleExpired($now)
                    && $this->getManagedCount() > $this->options->minRetainedConnections);
            $healthy = ! $expired && $connection->ping($this->options->heartbeatTimeout);
        } catch (CanceledException $cancellation) {
            try {
                $this->discardHeartbeatConnection($connection);
            } catch (CanceledException) {
            } catch (Throwable $exception) {
                $this->report($exception);
            }

            throw $cancellation;
        } catch (Throwable $exception) {
            $this->report('Database heartbeat failed: ' . $exception);
            $healthy = false;
        }

        if ($healthy && ! $this->isClosed()) {
            $this->requeueConnection($connection);
        } else {
            $this->discardHeartbeatConnection($connection);
        }
    }

    /**
     * Discard an idle connection from the pool.
     */
    protected function discardHeartbeatConnection(PooledConnection $connection): void
    {
        try {
            if ($connection->hasOpenTransaction()) {
                $this->report('Database heartbeat found an idle connection with an open transaction.');
            }
        } catch (Throwable $exception) {
            $this->report('Database heartbeat transaction check failed: ' . $exception);
        }

        $this->destroyConnection($connection);
    }
}
