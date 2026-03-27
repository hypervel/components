<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory as FactoryContract;
use Hypervel\Contracts\Queue\Monitor as MonitorContract;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\ObjectPool\Traits\HasPoolProxy;
use Hypervel\Queue\Connectors\ConnectorInterface;
use Hypervel\Support\Queue\Concerns\ResolvesQueueRoutes;
use InvalidArgumentException;

/**
 * @mixin \Hypervel\Contracts\Queue\Queue
 */
class QueueManager implements FactoryContract, MonitorContract
{
    use HasPoolProxy;
    use ResolvesQueueRoutes;

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
        protected Container $app
    ) {
    }

    /**
     * Register an event listener for the before job event.
     */
    public function before(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\JobProcessing::class, $callback);
    }

    /**
     * Register an event listener for the after job event.
     */
    public function after(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\JobProcessed::class, $callback);
    }

    /**
     * Register an event listener for the exception occurred job event.
     */
    public function exceptionOccurred(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\JobExceptionOccurred::class, $callback);
    }

    /**
     * Register an event listener for the daemon queue loop.
     */
    public function looping(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\Looping::class, $callback);
    }

    /**
     * Register an event listener for the failed job event.
     */
    public function failing(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\JobFailed::class, $callback);
    }

    /**
     * Register an event listener for the daemon queue starting.
     */
    public function starting(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\WorkerStarting::class, $callback);
    }

    /**
     * Register an event listener for the daemon queue stopping.
     */
    public function stopping(mixed $callback): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(Events\WorkerStopping::class, $callback);
    }

    /**
     * Register the default queue route for a given class.
     *
     * @param array|class-string $class
     */
    public function route(array|string $class, ?string $queue = null, ?string $connection = null): void
    {
        $this->queueRoutes()->set($class, $queue, $connection);
    }

    /**
     * Pause a queue by its connection and name.
     */
    public function pause(string $connection, string $queue): void
    {
        $this->app->make('cache')
            ->store()
            ->forever("hypervel:queue:paused:{$connection}:{$queue}", true);

        $this->app->make(Dispatcher::class)->dispatch(
            new Events\QueuePaused($connection, $queue)
        );
    }

    /**
     * Pause a queue by its connection and name for a given amount of time.
     */
    public function pauseFor(string $connection, string $queue, DateInterval|DateTimeInterface|int $ttl): void
    {
        $this->app->make('cache')
            ->store()
            ->put("hypervel:queue:paused:{$connection}:{$queue}", true, $ttl);

        $this->app->make(Dispatcher::class)->dispatch(
            new Events\QueuePaused($connection, $queue, $ttl)
        );
    }

    /**
     * Resume a paused queue by its connection and name.
     */
    public function resume(string $connection, string $queue): void
    {
        $this->app->make('cache')
            ->store()
            ->forget("hypervel:queue:paused:{$connection}:{$queue}");

        $this->app->make(Dispatcher::class)->dispatch(
            new Events\QueueResumed($connection, $queue)
        );
    }

    /**
     * Determine if a queue is paused.
     */
    public function isPaused(string $connection, string $queue): bool
    {
        return (bool) $this->app->make('cache')
            ->store()
            ->get("hypervel:queue:paused:{$connection}:{$queue}", false);
    }

    /**
     * Indicate that queue workers should not poll for restart or pause signals.
     */
    public function withoutInterruptionPolling(): void
    {
        Worker::$restartable = false;
        Worker::$pausable = false;
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

        $resolver = fn () => $this->getConnector($config['driver'])
            ->connect($config)
            ->setConnectionName($name)
            ->setContainer($this->app) // @phpstan-ignore method.notFound (setContainer is on concrete Queue, not contract)
            ->setConfig($config);

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
        if ($name !== 'null') {
            return $this->app->make('config')->get("queue.connections.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the name of the default queue connection.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->make('config')->get('queue.default');
    }

    /**
     * Set the name of the default queue connection.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->app->make('config')->set('queue.default', $name);
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
    public function getApplication(): Container
    {
        return $this->app;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(Container $app): static
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
}
