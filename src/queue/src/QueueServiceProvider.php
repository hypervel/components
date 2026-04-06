<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Database\ModelIdentifier;
use Hypervel\Queue\Connectors\BackgroundConnector;
use Hypervel\Queue\Connectors\BeanstalkdConnector;
use Hypervel\Queue\Connectors\DatabaseConnector;
use Hypervel\Queue\Connectors\DeferredConnector;
use Hypervel\Queue\Connectors\FailoverConnector;
use Hypervel\Queue\Connectors\NullConnector;
use Hypervel\Queue\Connectors\RedisConnector;
use Hypervel\Queue\Connectors\SqsConnector;
use Hypervel\Queue\Connectors\SyncConnector;
use Hypervel\Queue\Console\BatchesTableCommand;
use Hypervel\Queue\Console\ClearCommand;
use Hypervel\Queue\Console\FailedTableCommand;
use Hypervel\Queue\Console\FlushFailedCommand;
use Hypervel\Queue\Console\ForgetFailedCommand;
use Hypervel\Queue\Console\ListenCommand;
use Hypervel\Queue\Console\ListFailedCommand;
use Hypervel\Queue\Console\MonitorCommand;
use Hypervel\Queue\Console\PauseCommand;
use Hypervel\Queue\Console\PruneBatchesCommand;
use Hypervel\Queue\Console\PruneFailedJobsCommand;
use Hypervel\Queue\Console\RestartCommand;
use Hypervel\Queue\Console\ResumeCommand;
use Hypervel\Queue\Console\RetryBatchCommand;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Console\TableCommand;
use Hypervel\Queue\Console\WorkCommand;
use Hypervel\Queue\Failed\DatabaseFailedJobProvider;
use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Queue\Failed\FileFailedJobProvider;
use Hypervel\Queue\Failed\NullFailedJobProvider;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class QueueServiceProvider extends ServiceProvider
{
    use SerializesAndRestoresModelIdentifiers;

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->configureSerializableClosureUses();

        $this->registerManager();
        $this->registerConnection();
        $this->registerWorker();
        $this->registerListener();
        $this->registerRoutes();
        $this->registerFailedJobServices();

        $this->commands([
            BatchesTableCommand::class,
            ClearCommand::class,
            FailedTableCommand::class,
            FlushFailedCommand::class,
            ForgetFailedCommand::class,
            ListenCommand::class,
            ListFailedCommand::class,
            MonitorCommand::class,
            PauseCommand::class,
            PruneBatchesCommand::class,
            PruneFailedJobsCommand::class,
            RestartCommand::class,
            ResumeCommand::class,
            RetryBatchCommand::class,
            RetryCommand::class,
            TableCommand::class,
            WorkCommand::class,
        ]);

        $this->registerLaravelInteropAliases();
    }

    /**
     * Configure serializable closure uses.
     */
    protected function configureSerializableClosureUses(): void
    {
        SerializableClosure::transformUseVariablesUsing(function ($data) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->getSerializedPropertyValue($value);
            }

            return $data;
        });

        SerializableClosure::resolveUseVariablesUsing(function ($data) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->getRestoredPropertyValue($value);
            }

            return $data;
        });
    }

    /**
     * Register class aliases for cross-framework queue interoperability.
     *
     * These aliases allow Hypervel workers to process jobs dispatched by Laravel
     * and vice versa. The payload's "job" field references Illuminate\Queue\CallQueuedHandler,
     * and serialized models use Illuminate\Contracts\Database\ModelIdentifier. Without these
     * aliases, Hypervel cannot resolve those classes from Laravel-dispatched job payloads.
     */
    protected function registerLaravelInteropAliases(): void
    {
        if (! class_exists(\Illuminate\Queue\CallQueuedHandler::class, autoload: false)) {
            class_alias(CallQueuedHandler::class, \Illuminate\Queue\CallQueuedHandler::class);
        }

        if (! class_exists(\Illuminate\Contracts\Database\ModelIdentifier::class, autoload: false)) {
            class_alias(ModelIdentifier::class, \Illuminate\Contracts\Database\ModelIdentifier::class);
        }
    }

    /**
     * Register the queue manager.
     */
    protected function registerManager(): void
    {
        $this->app->singleton('queue', function ($app) {
            $manager = tap(new QueueManager($app), function ($manager) {
                $this->registerConnectors($manager);
            });

            if (! $app->has(ExceptionHandler::class)) {
                return $manager;
            }

            $reportHandler = fn (Throwable $e) => $app->make(ExceptionHandler::class)->report($e);

            foreach (['background', 'deferred'] as $connector) {
                try {
                    $manager->connection($connector)
                        ->setExceptionCallback($reportHandler); // @phpstan-ignore method.notFound (setExceptionCallback is on concrete Queue, not contract)
                } catch (InvalidArgumentException) {
                    // Ignore exception when the connector is not configured.
                }
            }

            return $manager;
        });
    }

    /**
     * Register the default queue connection binding.
     */
    protected function registerConnection(): void
    {
        $this->app->singleton('queue.connection', fn ($app) => $app['queue']->connection());
    }

    /**
     * Register the connectors on the queue manager.
     */
    public function registerConnectors(QueueManager $manager): void
    {
        foreach (['Null', 'Sync', 'Deferred', 'Background', 'Failover', 'Database', 'Redis', 'Beanstalkd', 'Sqs'] as $connector) {
            $this->{"register{$connector}Connector"}($manager);
        }
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
        $manager->addConnector('deferred', fn () => new DeferredConnector);
    }

    /**
     * Register the Background queue connector.
     */
    protected function registerBackgroundConnector(QueueManager $manager): void
    {
        $manager->addConnector('background', fn () => new BackgroundConnector);
    }

    /**
     * Register the Failover queue connector.
     */
    protected function registerFailoverConnector(QueueManager $manager): void
    {
        $manager->addConnector('failover', fn () => new FailoverConnector(
            $this->app->make('queue'),
            $this->app->make(EventDispatcher::class),
        ));
    }

    /**
     * Register the database queue connector.
     */
    protected function registerDatabaseConnector(QueueManager $manager): void
    {
        $manager->addConnector('database', fn () => new DatabaseConnector(
            $this->app['db'],
        ));
    }

    /**
     * Register the Redis queue connector.
     */
    protected function registerRedisConnector(QueueManager $manager): void
    {
        $manager->addConnector('redis', fn () => new RedisConnector(
            $this->app['redis'],
        ));
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

    /**
     * Register the queue worker.
     */
    protected function registerWorker(): void
    {
        $this->app->singleton('queue.worker', function ($app) {
            return new Worker(
                $app['queue'],
                $app['events'],
                $app->make(ExceptionHandler::class),
                fn () => $app->isDownForMaintenance(),
            );
        });
    }

    /**
     * Register the queue listener.
     */
    protected function registerListener(): void
    {
        $this->app->singleton('queue.listener', fn ($app) => new Listener($app->basePath()));
    }

    /**
     * Register the default queue routes binding.
     */
    protected function registerRoutes(): void
    {
        $this->app->singleton('queue.routes', fn () => new QueueRoutes);
    }

    /**
     * Register the failed job services.
     */
    protected function registerFailedJobServices(): void
    {
        $this->app->singleton('queue.failer', function ($app) {
            $config = $app['config']['queue.failed'] ?? [];

            if (array_key_exists('driver', $config)
                && (is_null($config['driver']) || $config['driver'] === 'null')
            ) {
                return new NullFailedJobProvider;
            }

            if (isset($config['driver']) && $config['driver'] === 'file') {
                return new FileFailedJobProvider(
                    $config['path'] ?? $app->basePath('storage/framework/cache/failed-jobs.json'),
                    $config['limit'] ?? 100,
                    fn () => $app['cache']->store('file'),
                );
            }

            if (isset($config['driver']) && $config['driver'] === 'database-uuids') {
                return new DatabaseUuidFailedJobProvider(
                    $app['db'],
                    $config['table'],
                    $config['database'],
                );
            }

            if (isset($config['table'])) {
                return new DatabaseFailedJobProvider(
                    $app['db'],
                    $config['table'],
                    $config['database'],
                );
            }

            return new NullFailedJobProvider;
        });
    }
}
