<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Console\Migrations\FreshCommand;
use Hypervel\Database\Console\Migrations\InstallCommand;
use Hypervel\Database\Console\Migrations\MakeMigrationCommand;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Console\Migrations\StatusCommand;
use Hypervel\Database\Console\SeedCommand;
use Hypervel\Database\Console\ShowModelCommand;
use Hypervel\Database\Console\WipeCommand;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Listeners\UnsetContextInTaskWorkerListener;
use Hypervel\Database\Migrations\DatabaseMigrationRepository;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Schema\SchemaProxy;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerConnectionServices();

        $this->app->singleton(ConnectionResolverInterface::class, fn ($app) => $app->make(ConnectionResolver::class));

        $this->app->singleton(MigrationRepositoryInterface::class, function ($app) {
            $migrations = $app->make('config')->get('database.migrations', 'migrations');

            $table = is_array($migrations)
                ? ($migrations['table'] ?? 'migrations')
                : $migrations;

            return new DatabaseMigrationRepository(
                $app->make(ConnectionResolverInterface::class),
                $table,
            );
        });

        $this->commands([
            FreshCommand::class,
            InstallCommand::class,
            MakeMigrationCommand::class,
            MigrateCommand::class,
            RefreshCommand::class,
            ResetCommand::class,
            RollbackCommand::class,
            SeedCommand::class,
            ShowModelCommand::class,
            StatusCommand::class,
            WipeCommand::class,
        ]);
    }

    /**
     * Register the primary database bindings.
     */
    protected function registerConnectionServices(): void
    {
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app->make(ConnectionFactory::class));
        });

        $this->app->singleton('db.schema', function () {
            return new SchemaProxy();
        });

        $this->app->singleton('db.transactions', function () {
            return new DatabaseTransactionsManager();
        });
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        Model::setConnectionResolver($this->app->make(ConnectionResolverInterface::class));
        Model::setEventDispatcher($this->app['events']);

        $events = $this->app->make('events');

        $events->listen(BeforeWorkerStart::class, function (BeforeWorkerStart $event) {
            $this->app->make(UnsetContextInTaskWorkerListener::class)->process($event);
        });
    }
}
