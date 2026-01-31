<?php

declare(strict_types=1);

namespace Hypervel\Database\Capsule;

use Closure;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\SimpleConnectionResolver;
use Hypervel\Support\Traits\CapsuleManagerTrait;
use PDO;

class Manager
{
    use CapsuleManagerTrait;

    /**
     * The database manager instance.
     */
    protected DatabaseManager $manager;

    /**
     * Create a new database capsule manager.
     */
    public function __construct(?ContainerContract $container = null)
    {
        $this->setupContainer($container ?: new Container(new DefinitionSource([])));

        // Once we have the container setup, we will setup the default configuration
        // options in the container "config" binding. This will make the database
        // manager work correctly out of the box without extreme configuration.
        $this->setupDefaultConfiguration();

        $this->setupManager();
    }

    /**
     * Setup the default database configuration options.
     */
    protected function setupDefaultConfiguration(): void
    {
        $this->container['config']['database.fetch'] = PDO::FETCH_OBJ;

        $this->container['config']['database.default'] = 'default';
    }

    /**
     * Build the database manager instance.
     */
    protected function setupManager(): void
    {
        $factory = new ConnectionFactory($this->container);

        $this->manager = new DatabaseManager($this->container, $factory);

        // Bind a simple non-pooled resolver for Capsule use.
        // This is required because DatabaseManager delegates connection
        // resolution to ConnectionResolverInterface.
        $this->container->instance(
            ConnectionResolverInterface::class,
            new SimpleConnectionResolver($this->manager)
        );
    }

    /**
     * Get a connection instance from the global manager.
     */
    public static function connection(?string $connection = null): ConnectionInterface
    {
        return static::$instance->getConnection($connection);
    }

    /**
     * Get a fluent query builder instance.
     *
     * @param Builder|Closure|string $table
     */
    public static function table($table, ?string $as = null, ?string $connection = null): Builder
    {
        return static::$instance->connection($connection)->table($table, $as);
    }

    /**
     * Get a schema builder instance.
     */
    public static function schema(?string $connection = null): \Hypervel\Database\Schema\Builder
    {
        return static::$instance->connection($connection)->getSchemaBuilder();
    }

    /**
     * Get a registered connection instance.
     */
    public function getConnection(?string $name = null): ConnectionInterface
    {
        return $this->manager->connection($name);
    }

    /**
     * Register a connection with the manager.
     */
    public function addConnection(array $config, string $name = 'default'): void
    {
        $connections = $this->container['config']['database.connections'];

        $connections[$name] = $config;

        $this->container['config']['database.connections'] = $connections;
    }

    /**
     * Bootstrap Eloquent so it is ready for usage.
     */
    public function bootEloquent(): void
    {
        Eloquent::setConnectionResolver($this->manager);

        // If we have an event dispatcher instance, we will go ahead and register it
        // with the Eloquent ORM, allowing for model callbacks while creating and
        // updating "model" instances; however, it is not necessary to operate.
        if ($dispatcher = $this->getEventDispatcher()) {
            Eloquent::setEventDispatcher($dispatcher);
        }
    }

    /**
     * Set the fetch mode for the database connections.
     */
    public function setFetchMode(int $fetchMode): static
    {
        $this->container['config']['database.fetch'] = $fetchMode;

        return $this;
    }

    /**
     * Get the database manager instance.
     */
    public function getDatabaseManager(): DatabaseManager
    {
        return $this->manager;
    }

    /**
     * Get the current event dispatcher instance.
     */
    public function getEventDispatcher(): ?Dispatcher
    {
        if ($this->container->bound('events')) {
            return $this->container['events'];
        }

        return null;
    }

    /**
     * Set the event dispatcher instance to be used by connections.
     */
    public function setEventDispatcher(Dispatcher $dispatcher): void
    {
        $this->container->instance('events', $dispatcher);
    }

    /**
     * Dynamically pass methods to the default connection.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->{$method}(...$parameters);
    }
}
