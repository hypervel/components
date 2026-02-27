<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Cache\Factory as CacheFactoryContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Queue\Console\ClearCommand;
use Hypervel\Queue\Console\FlushFailedCommand;
use Hypervel\Queue\Console\ForgetFailedCommand;
use Hypervel\Queue\Console\ListenCommand;
use Hypervel\Queue\Console\ListFailedCommand;
use Hypervel\Queue\Console\MonitorCommand;
use Hypervel\Queue\Console\PruneBatchesCommand;
use Hypervel\Queue\Console\PruneFailedJobsCommand;
use Hypervel\Queue\Console\RestartCommand;
use Hypervel\Queue\Console\RetryBatchCommand;
use Hypervel\Queue\Console\RetryCommand;
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
            ClearCommand::class,
            FlushFailedCommand::class,
            ForgetFailedCommand::class,
            ListenCommand::class,
            ListFailedCommand::class,
            MonitorCommand::class,
            PruneBatchesCommand::class,
            PruneFailedJobsCommand::class,
            RestartCommand::class,
            RetryBatchCommand::class,
            RetryCommand::class,
            WorkCommand::class,
        ]);
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
     * Register the queue manager.
     */
    protected function registerManager(): void
    {
        $this->app->singleton('queue', function ($app) {
            $manager = new QueueManager($app);

            if (! $app->has(ExceptionHandler::class)) {
                return $manager;
            }

            $reportHandler = fn (Throwable $e) => $app->make(ExceptionHandler::class)->report($e);

            foreach (['coroutine', 'defer'] as $connector) {
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
     * Register the queue worker.
     */
    protected function registerWorker(): void
    {
        $this->app->singleton('queue.worker', function ($app) {
            return new Worker(
                $app['queue'],
                $app['events'],
                $app->make(ExceptionHandler::class),
                fn () => false,
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
        $this->app->singleton('queue.routes', fn () => new QueueRoutes());
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
                return new NullFailedJobProvider();
            }

            if (isset($config['driver']) && $config['driver'] === 'file') {
                return new FileFailedJobProvider(
                    $config['path'] ?? $app->basePath('storage/framework/cache/failed-jobs.json'),
                    $config['limit'] ?? 100,
                    fn () => $app->make(CacheFactoryContract::class)->store('file'),
                );
            }

            if (isset($config['driver']) && $config['driver'] === 'database-uuids') {
                return new DatabaseUuidFailedJobProvider(
                    $app->make(ConnectionResolverInterface::class),
                    $config['table'],
                    $config['database'],
                );
            }

            if (isset($config['table'])) {
                return new DatabaseFailedJobProvider(
                    $app->make(ConnectionResolverInterface::class),
                    $config['table'],
                    $config['database'],
                );
            }

            return new NullFailedJobProvider();
        });
    }
}
