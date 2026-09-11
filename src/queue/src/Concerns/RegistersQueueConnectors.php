<?php

declare(strict_types=1);

namespace Hypervel\Queue\Concerns;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Queue\Connectors\BackgroundConnector;
use Hypervel\Queue\Connectors\BeanstalkdConnector;
use Hypervel\Queue\Connectors\DatabaseConnector;
use Hypervel\Queue\Connectors\DeferredConnector;
use Hypervel\Queue\Connectors\FailoverConnector;
use Hypervel\Queue\Connectors\NullConnector;
use Hypervel\Queue\Connectors\RedisConnector;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\Connectors\SyncConnector;
use Hypervel\Queue\QueueManager;
use Throwable;

trait RegistersQueueConnectors
{
    /**
     * Get the container used to resolve connector dependencies.
     */
    abstract protected function connectorContainer(): Container;

    /**
     * Register the connectors on the queue manager.
     */
    protected function registerDefaultConnectors(QueueManager $manager): void
    {
        foreach (['Null', 'Sync', 'Deferred', 'Background', 'Failover', 'Database', 'Redis', 'Beanstalkd', 'Sqs'] as $connector) {
            $this->{"register{$connector}Connector"}($manager);
        }
    }

    /**
     * Get the exception reporter for in-process queue connections.
     */
    protected function exceptionReporter(): ?Closure
    {
        if (! $this->connectorContainer()->has(ExceptionHandler::class)) {
            return null;
        }

        return fn (Throwable $exception) => $this->connectorContainer()->make(ExceptionHandler::class)->report($exception);
    }

    /**
     * Register the Null queue connector.
     */
    protected function registerNullConnector(QueueManager $manager): void
    {
        $manager->addConnector('null', fn () => new NullConnector);
    }

    /**
     * Register the Sync queue connector.
     */
    protected function registerSyncConnector(QueueManager $manager): void
    {
        $manager->addConnector('sync', fn () => new SyncConnector);
    }

    /**
     * Register the Deferred queue connector.
     */
    protected function registerDeferredConnector(QueueManager $manager): void
    {
        $manager->addConnector('deferred', fn () => new DeferredConnector($this->exceptionReporter()));
    }

    /**
     * Register the Background queue connector.
     */
    protected function registerBackgroundConnector(QueueManager $manager): void
    {
        $manager->addConnector('background', fn () => new BackgroundConnector($this->exceptionReporter()));
    }

    /**
     * Register the Failover queue connector.
     */
    protected function registerFailoverConnector(QueueManager $manager): void
    {
        $manager->addConnector('failover', fn () => new FailoverConnector(
            $manager,
            $this->connectorContainer()->make(EventDispatcher::class),
        ));
    }

    /**
     * Register the database queue connector.
     */
    protected function registerDatabaseConnector(QueueManager $manager): void
    {
        $manager->addConnector('database', function (): DatabaseConnector {
            /** @var ConnectionResolverInterface $connections */
            $connections = $this->connectorContainer()->make('db');

            return new DatabaseConnector($connections);
        });
    }

    /**
     * Register the Redis queue connector.
     */
    protected function registerRedisConnector(QueueManager $manager): void
    {
        $manager->addConnector('redis', function (): RedisConnector {
            /** @var RedisFactory $redis */
            $redis = $this->connectorContainer()->make('redis');

            return new RedisConnector($redis);
        });
    }

    /**
     * Register the Beanstalkd queue connector.
     */
    protected function registerBeanstalkdConnector(QueueManager $manager): void
    {
        $manager->addConnector('beanstalkd', fn () => new BeanstalkdConnector);
    }

    /**
     * Register the Amazon SQS queue connector.
     */
    protected function registerSqsConnector(QueueManager $manager): void
    {
        $manager->addConnector('sqs', fn () => new SqsConnector);
    }
}
