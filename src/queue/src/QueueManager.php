<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Redis\RedisFactory;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Queue\Connectors\BeanstalkdConnector;
use Hypervel\Queue\Connectors\ConnectorInterface;
use Hypervel\Queue\Connectors\DatabaseConnector;
use Hypervel\Queue\Connectors\DeferConnector;
use Hypervel\Queue\Connectors\NullConnector;
use Hypervel\Queue\Connectors\RedisConnector;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\Connectors\SyncConnector;
use Hypervel\Queue\Contracts\Factory as FactoryContract;
use Hypervel\Queue\Contracts\Monitor as MonitorContract;
use Hypervel\Queue\Contracts\Queue;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @mixin \Hypervel\Queue\Contracts\Queue
 */
class QueueManager implements FactoryContract, MonitorContract
{
    use HasPoolProxy;

    /**
     * The config instance.
     */
    protected ConfigInterface $config;

    /**
     * The array of resolved queue connections.
     */
    protected array $connections = [];

    /**
     * The array of resolved queue connectors.
     */
    protected array $connectors = [];

    /**
     * The pool proxy class.
     */
    protected string $poolProxyClass = QueuePoolProxy::class;

    /**
     * The array of drivers which will be wrapped as pool proxies.
     */
    protected array $poolables = ['beanstalkd', 'sqs'];

    /**
     * Create a new queue manager instance.
     */
    public function __construct(
        protected ContainerInterface $app
    ) {
        $this->config = $app->get(ConfigInterface::class);

        $this->registerConnectors();
    }

    /**
     * Register an event listener for the before job event.
     */
    public function before(mixed $callback): void
    {
        $this->app->get(EventDispatcherInterface::class)
            ->listen(Events\JobProcessing::class, $callback);
    }

    /**
     * Register an event listener for the after job event.
     */
    public function after(mixed $callback): void
    {
        $this->app->get(EventDispatcherInterface::class)
            ->listen(Events\JobProcessed::class, $callback);
    }

    /**
     * Register an event listener for the exception occurred job event.
     */
    public function exceptionOccurred(mixed $callback): void
    {
        $this->app->get(EventDispatcherInterface::class)
            ->listen(Events\JobExceptionOccurred::class, $callback);
    }

    /**
     * Register an event listener for the daemon queue loop.
     */
    public function looping(mixed $callback): void
    {
        $this->app->get(EventDispatcherInterface::class)
            ->listen(Events\Looping::class, $callback);
    }

    /**
     * Register an event listener for the failed job event.
     */
    public function failing(mixed $callback): void
    {
        $this->app->get(EventDispatcherInterface::class)
            ->listen(Events\JobFailed::class, $callback);
    }

    /**
     * Register an event listener for the daemon queue stopping.
     */
    public function stopping(mixed $callback): void
    {
        $this->app->get(EventDispatcherInterface::class)
            ->listen(Events\WorkerStopping::class, $callback);
    }

    /**
     * Determine if the driver is connected.
     */
    public function connected(?string $name = null): bool
    {
        return isset($this->connections[$name ?: $this->getDefaultDriver()]);
    }

    /**
     * Resolve a queue connection instance.
     */
    public function connection(?string $name = null): Queue
    {
        $name = $name ?: $this->getDefaultDriver();

        // If the connection has not been resolved yet we will resolve it now as all
        // of the connections are resolved when they are actually needed so we do
        // not make any unnecessary connection to the various queue end-points.
        if ($queue = $this->connections[$name] ?? null) {
            return $queue;
        }

        return $this->connections[$name] = $this->resolve($name);
    }

    /**
     * Resolve a queue connection.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Queue
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("The [{$name}] queue connection has not been configured.");
        }

        /** @phpstan-ignore-next-line */
        $resolver = fn () => $this->getConnector($config['driver'])
            ->connect($config)
            ->setConnectionName($name)
            ->setContainer($this->app);

        if (in_array($config['driver'], $this->poolables)) {
            return $this->createPoolProxy(
                $name,
                $resolver,
                $config['pool'] ?? []
            );
        }

        return $resolver();
    }

    /**
     * Get the connector for a given driver.
     *
     * @throws InvalidArgumentException
     */
    protected function getConnector(string $driver): ConnectorInterface
    {
        if (! isset($this->connectors[$driver])) {
            throw new InvalidArgumentException("No connector for [{$driver}].");
        }

        return call_user_func($this->connectors[$driver]);
    }

    /**
     * Add a queue connection resolver.
     */
    public function extend(string $driver, Closure $resolver): void
    {
        $this->addConnector($driver, $resolver);
    }

    /**
     * Add a queue connection resolver.
     */
    public function addConnector(string $driver, Closure $resolver): void
    {
        $this->connectors[$driver] = $resolver;
    }

    /**
     * Get the queue connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if (! is_null($name) && $name !== 'null') {
            return $this->config->get("queue.connections.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the name of the default queue connection.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('queue.default');
    }

    /**
     * Set the name of the default queue connection.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->config->set('queue.default', $name);
    }

    /**
     * Get the full name for the given connection.
     */
    public function getName(?string $connection = null): string
    {
        return $connection ?: $this->getDefaultDriver();
    }

    /**
     * Get the application instance used by the manager.
     */
    public function getApplication(): ContainerInterface
    {
        return $this->app;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(ContainerInterface $app): static
    {
        $this->app = $app;

        foreach ($this->connections as $connection) {
            $connection->setContainer($app);
        }

        return $this;
    }

    /**
     * Dynamically pass calls to the default connection.
     */
    public function __call(string $method, array $parameters)
    {
        return $this->connection()->{$method}(...$parameters);
    }

    /**
     * Register the connectors on the queue manager.
     */
    protected function registerConnectors(): void
    {
        $this->registerNullConnector();
        $this->registerSyncConnector();
        $this->registerDatabaseConnector();
        $this->registerRedisConnector();
        $this->registerBeanstalkdConnector();
        $this->registerSqsConnector();
        $this->registerDeferConnector();
    }

    /**
     * Register the Null queue connector.
     */
    protected function registerNullConnector(): void
    {
        $this->addConnector('null', function () {
            return new NullConnector();
        });
    }

    /**
     * Register the Sync queue connector.
     */
    protected function registerSyncConnector(): void
    {
        $this->addConnector('sync', function () {
            return new SyncConnector();
        });
    }

    /**
     * Register the database queue connector.
     */
    protected function registerDatabaseConnector(): void
    {
        $this->addConnector('database', function () {
            return new DatabaseConnector(
                $this->app->get(ConnectionResolverInterface::class)
            );
        });
    }

    /**
     * Register the Redis queue connector.
     */
    protected function registerRedisConnector(): void
    {
        $this->addConnector('redis', function () {
            return new RedisConnector(
                $this->app->get(RedisFactory::class)
            );
        });
    }

    /**
     * Register the Beanstalkd queue connector.
     */
    protected function registerBeanstalkdConnector(): void
    {
        $this->addConnector('beanstalkd', function () {
            return new BeanstalkdConnector();
        });
    }

    /**
     * Register the Amazon SQS queue connector.
     */
    protected function registerSqsConnector(): void
    {
        $this->addConnector('sqs', function () {
            return new SqsConnector();
        });
    }

    /**
     * Register the Coroutine defer queue connector.
     */
    protected function registerDeferConnector(): void
    {
        $this->addConnector('defer', function () {
            return new DeferConnector();
        });
    }
}
