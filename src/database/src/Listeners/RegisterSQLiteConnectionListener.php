<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hyperf\Context\ApplicationContext;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hypervel\Database\Connection;
use Hypervel\Database\SQLiteConnection;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Registers a custom SQLite connection resolver that handles in-memory databases.
 *
 * When using connection pooling with SQLite in-memory databases (:memory:),
 * each pooled connection would normally get its own separate in-memory database,
 * causing "table not found" errors since migrations only run on one connection.
 *
 * This listener registers a resolver that stores a persistent PDO instance in
 * the container for in-memory databases, ensuring all pooled connections share
 * the same in-memory database.
 */
class RegisterSQLiteConnectionListener implements ListenerInterface
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        Connection::resolverFor('sqlite', function ($connection, $database, $prefix, $config) {
            if ($this->isInMemoryDatabase($config['database'] ?? '')) {
                $connection = $this->createPersistentPdoResolver($connection, $config);
            }

            return new SQLiteConnection($connection, $database, $prefix, $config);
        });
    }

    /**
     * Determine if the database configuration is for an in-memory database.
     *
     * Matches the detection logic in SQLiteConnector::parseDatabasePath().
     */
    protected function isInMemoryDatabase(string $database): bool
    {
        return $database === ':memory:'
            || str_contains($database, '?mode=memory')
            || str_contains($database, '&mode=memory');
    }

    /**
     * Create a PDO resolver that returns a persistent (singleton) PDO instance.
     *
     * The PDO is stored in the container under a connection-specific key,
     * ensuring all pooled connections for this in-memory database share
     * the same underlying PDO instance.
     *
     * @param \Closure|PDO $connection The original PDO or PDO-creating closure
     * @param array $config The connection configuration
     * @return \Closure A closure that returns the persistent PDO
     */
    protected function createPersistentPdoResolver(\Closure|PDO $connection, array $config): \Closure
    {
        return function () use ($connection, $config): PDO {
            /** @var \Hyperf\Contract\ContainerInterface $container */
            $container = ApplicationContext::getContainer();
            $key = 'sqlite.persistent.pdo.' . ($config['name'] ?? 'default');

            if (! $container->has($key)) {
                $pdo = $connection instanceof \Closure ? $connection() : $connection;
                $container->set($key, $pdo);
            }

            return $container->get($key);
        };
    }
}
