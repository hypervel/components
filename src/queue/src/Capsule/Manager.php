<?php

declare(strict_types=1);

namespace Hypervel\Queue\Capsule;

use DateInterval;
use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueServiceProvider;
use Hypervel\Support\Traits\CapsuleManagerTrait;

/**
 * @mixin QueueManager
 * @mixin Queue
 */
class Manager
{
    use CapsuleManagerTrait;

    /**
     * The queue manager instance.
     */
    protected QueueManager $manager;

    /**
     * Create a new queue capsule manager.
     */
    public function __construct(?Container $container = null)
    {
        $this->setupContainer($container ?: new Container);

        $this->setupDefaultConfiguration();

        $this->setupManager();

        $this->registerConnectors();
    }

    /**
     * Setup the default queue configuration options.
     */
    protected function setupDefaultConfiguration(): void
    {
        $this->container['config']['queue.default'] = 'default';
    }

    /**
     * Build the queue manager instance.
     */
    protected function setupManager(): void
    {
        $this->manager = new QueueManager($this->container);
    }

    /**
     * Register the default connectors that the component ships with.
     */
    protected function registerConnectors(): void
    {
        // Capsule intentionally reuses the provider's connector registration logic with its
        // standalone container; this works in practice and only differs from the provider's
        // stricter application constructor type.
        /** @phpstan-ignore-next-line */
        $provider = new QueueServiceProvider($this->container);

        $provider->registerConnectors($this->manager);
    }

    /**
     * Get a connection instance from the global manager.
     */
    public static function connection(?string $connection = null): Queue
    {
        return static::$instance->getConnection($connection);
    }

    /**
     * Push a new job onto the queue.
     */
    public static function push(object|string $job, mixed $data = '', ?string $queue = null, ?string $connection = null): mixed
    {
        return static::$instance->connection($connection)->push($job, $data, $queue);
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public static function bulk(array $jobs, mixed $data = '', ?string $queue = null, ?string $connection = null): mixed
    {
        return static::$instance->connection($connection)->bulk($jobs, $data, $queue);
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public static function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null, ?string $connection = null): mixed
    {
        return static::$instance->connection($connection)->later($delay, $job, $data, $queue);
    }

    /**
     * Get a registered connection instance.
     */
    public function getConnection(?string $name = null): Queue
    {
        return $this->manager->connection($name);
    }

    /**
     * Register a connection with the manager.
     */
    public function addConnection(array $config, string $name = 'default'): void
    {
        $this->container['config']["queue.connections.{$name}"] = $config;
    }

    /**
     * Get the queue manager instance.
     */
    public function getQueueManager(): QueueManager
    {
        return $this->manager;
    }

    /**
     * Pass dynamic instance methods to the manager.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->manager->{$method}(...$parameters);
    }

    /**
     * Dynamically pass methods to the default connection.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::connection()->{$method}(...$parameters);
    }
}
