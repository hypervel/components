<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Queue\Concerns\RegistersQueueConnectors;
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

class QueueServiceProvider extends ServiceProvider
{
    use RegistersQueueConnectors;
    use SerializesAndRestoresModelIdentifiers;

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->configureSerializableClosureUses();

        $this->registerCallQueuedHandler();
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
     * Register the queued job handler.
     */
    protected function registerCallQueuedHandler(): void
    {
        // The handler retains per-job command state, so concurrent jobs need fresh instances.
        $this->app->bind(CallQueuedHandler::class);
        $this->app->bind('Illuminate\Queue\CallQueuedHandler', CallQueuedHandler::class);
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
        if (! class_exists('Illuminate\Queue\CallQueuedHandler', autoload: false)) {
            class_alias(CallQueuedHandler::class, 'Illuminate\Queue\CallQueuedHandler');
        }

        if (! class_exists('Illuminate\Contracts\Database\ModelIdentifier', autoload: false)) {
            class_alias(ModelIdentifier::class, 'Illuminate\Contracts\Database\ModelIdentifier');
        }
    }

    /**
     * Register the queue manager.
     */
    protected function registerManager(): void
    {
        $this->app->singleton('queue', function ($app) {
            return tap(new QueueManager($app), function ($manager) {
                $this->registerConnectors($manager);
            });
        });
    }

    /**
     * Register the default queue connection binding.
     */
    protected function registerConnection(): void
    {
        $this->app->singleton('queue.connection', fn ($app) => $app->make('queue')->connection());
    }

    /**
     * Register the connectors on the queue manager.
     *
     * Boot-only. Connectors persist on the supplied manager for the worker
     * lifetime and affect every subsequent connection it resolves.
     */
    public function registerConnectors(QueueManager $manager): void
    {
        $this->registerDefaultConnectors($manager);
    }

    /**
     * Get the container used to resolve connector dependencies.
     */
    protected function connectorContainer(): Container
    {
        return $this->app;
    }

    /**
     * Register the queue worker.
     */
    protected function registerWorker(): void
    {
        $this->app->singleton('queue.worker', function ($app) {
            return new Worker(
                $app->make('queue'),
                $app->make('events'),
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
        $this->app->singleton('queue.routes', fn ($app) => $app->make(QueueRoutes::class));
    }

    /**
     * Register the failed job services.
     */
    protected function registerFailedJobServices(): void
    {
        $this->app->singleton('queue.failer', function ($app) {
            $config = $app->make('config')->array('queue.failed');
            $driver = $config['driver'];

            return match ($driver) {
                null, 'null' => new NullFailedJobProvider,
                'file' => new FileFailedJobProvider(
                    $config['path'] ?? $app->storagePath('framework/cache/failed-jobs.json'),
                    $config['limit'] ?? 100,
                    fn () => $app->make('cache')->store('file'),
                ),
                'database-uuids' => new DatabaseUuidFailedJobProvider(
                    $app->make('db'),
                    $config['database'],
                    $config['table'],
                ),
                'database' => new DatabaseFailedJobProvider(
                    $app->make('db'),
                    $config['database'],
                    $config['table'],
                ),
                default => throw new InvalidArgumentException(
                    'Unsupported failed job provider [' . (is_string($driver) ? $driver : get_debug_type($driver)) . '].'
                ),
            };
        });
    }
}
